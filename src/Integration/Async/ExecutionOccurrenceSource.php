<?php

declare(strict_types=1);

namespace Yiisoft\Scheduler\Integration\Async;

use DateTimeImmutable;
use InvalidArgumentException;
use Psr\Clock\ClockInterface;
use Yiisoft\Scheduler\Config\{MissedOccurrencePolicy, MissedOccurrenceSetting};
use Yiisoft\Scheduler\Trigger\{TriggerInterface, TriggerRangeInterface};
use DateTimeZone;

use function count;
use function array_slice;

use const STR_PAD_LEFT;

final class ExecutionOccurrenceSource implements ExecutionOccurrenceSourceInterface
{
    private ?ExecutionOccurrenceCandidate $pending = null;
    private ?string $scanCheckpoint = null;
    private bool $checkpointPrimed = false;
    private ?DateTimeImmutable $scanNow = null;
    private ?DateTimeImmutable $fallbackCursor = null;
    /** @var list<DateTimeImmutable> */
    private array $fallbackMissed = [];
    private bool $scanExhausted = false;
    private readonly ?ExecutionLedgerQueryInterface $ledger;
    private readonly MissedOccurrenceSetting $policy;

    public function __construct(
        private readonly string $taskKey,
        private readonly TriggerInterface $trigger,
        private readonly ExecutionBindingSourceInterface $bindings,
        private readonly ClockInterface $clock,
        private readonly ExecutionOccurrenceCheckpointInterface $checkpoint,
        mixed $ledgerOrPolicy = null,
        MissedOccurrenceSetting|MissedOccurrencePolicy $policy = new MissedOccurrenceSetting(),
        /**
         * The persisted cursor written immediately before a first explicit cron
         * anchor. Comparing against the checkpoint makes the first-run decision
         * survive a process restart without adding checkpoint state.
         */
        private readonly ?string $initialAnchorCursor = null,
        ?string $initialCheckpoint = null,
    ) {
        $this->scanCheckpoint = $initialCheckpoint;
        $this->checkpointPrimed = $initialCheckpoint !== null;
        // The enum form is retained only for source-level construction of the default skip source.
        $setting = $policy instanceof MissedOccurrencePolicy
            ? new MissedOccurrenceSetting($policy)
            : $policy;
        if ($ledgerOrPolicy instanceof MissedOccurrencePolicy) {
            $setting = new MissedOccurrenceSetting($ledgerOrPolicy);
        }

        $ledger = $ledgerOrPolicy instanceof ExecutionLedgerQueryInterface ? $ledgerOrPolicy : null;
        if ($ledger === null && $setting->policy !== MissedOccurrencePolicy::SKIP) {
            throw new InvalidArgumentException('A journal history port is required for replay policies.');
        }
        $this->ledger = $ledger;
        $this->policy = $setting;
    }

    public function next(): ?ExecutionOccurrenceCandidate
    {
        $wasPrimed = $this->checkpointPrimed;
        $cursor = $wasPrimed || $this->scanCheckpoint !== null
            ? $this->scanCheckpoint
            : $this->checkpoint->read($this->taskKey);
        $this->checkpointPrimed = false;
        if ($cursor === null) {
            throw new InvalidArgumentException('An occurrence checkpoint is required before reading the source.');
        }
        if ($wasPrimed || $this->scanCheckpoint !== $cursor) {
            $this->resetScan($cursor);
        }
        if ($this->pending !== null) {
            return $this->pending;
        }

        $hasHistory = $this->policy->policy !== MissedOccurrencePolicy::SKIP
            && $this->ledger !== null
            && $this->ledger->hasTaskHistory($this->taskKey);
        $last = self::decodeCursor($cursor);
        if ($this->trigger instanceof TriggerRangeInterface) {
            return $this->nextFromRange($last, $hasHistory);
        }
        return $this->nextFromFallback($hasHistory);
    }

    public function acknowledge(ExecutionOccurrenceCandidate $candidate, ExecutionAdmission $admission): void
    {
        $current = $this->next();
        if ($current === null || !$current->isSameCandidate($candidate) || !$candidate->matchesAdmission($admission)) {
            throw new InvalidArgumentException('Candidate acknowledgement does not match the current committed admission.');
        }
        $expectedCursor = $this->checkpoint->read($this->taskKey);
        if ($expectedCursor === null) {
            throw new InvalidArgumentException('An occurrence checkpoint is required before acknowledgement.');
        }
        $this->checkpoint->advance($this->taskKey, $expectedCursor, $candidate->cursor);
        $this->resetScan($candidate->cursor);
    }

    public function skip(ExecutionOccurrenceCandidate $candidate, OccurrenceSkipReason $reason): void
    {
        $current = $this->next();
        if ($current === null || !$current->isSameCandidate($candidate)) {
            throw new InvalidArgumentException('Candidate skip does not match the current candidate.');
        }
        $expectedCursor = $this->checkpoint->read($this->taskKey);
        if ($expectedCursor === null) {
            throw new InvalidArgumentException('An occurrence checkpoint is required before skipping.');
        }
        $this->checkpoint->skip($this->taskKey, $expectedCursor, $candidate->cursor, $reason);
        $this->resetScan($candidate->cursor);
    }

    private function nextFromRange(DateTimeImmutable $last, bool $hasHistory): ?ExecutionOccurrenceCandidate
    {
        if ($this->scanExhausted) {
            return null;
        }
        $now = $this->scanNow;
        if ($now === null) {
            throw new InvalidArgumentException('Replay window was not initialized.');
        }
        $trigger = $this->trigger;
        if (!$trigger instanceof TriggerRangeInterface) {
            throw new InvalidArgumentException('A range trigger is required for range occurrence scanning.');
        }
        if ($hasHistory) {
            $limit = $this->policy->policy === MissedOccurrencePolicy::LAST ? 1 : $this->policy->max;
            if ($limit === null) {
                throw new InvalidArgumentException('The all missed occurrence policy requires a positive max.');
            }
            /** @var list<DateTimeImmutable> $dates */
            $dates = $trigger->getRunDates($last, $now, $limit, $last);
            $this->scanExhausted = true;
            if ($dates !== []) {
                $date = $this->policy->policy === MissedOccurrencePolicy::LAST
                    ? $dates[count($dates) - 1]
                    : $dates[0];
                return $this->pending = $this->candidate($date->getTimestamp());
            }
            $date = $trigger->getNextRunDateAtOrAfter($now, $last);
            return $date === null ? null : $this->pending = $this->candidate($date->getTimestamp());
        }
        $this->scanExhausted = true;
        // An unconsumed explicit anchor starts just before its configured range.
        // This admits that deliberate first occurrence, without turning ordinary
        // historyless missed slots into work.
        $date = $this->initialAnchorCursor !== null
            && $this->scanCheckpoint === $this->initialAnchorCursor
            ? $trigger->getNextRunDateAtOrAfter($last, $last)
            : $trigger->getNextRunDateAtOrAfter($now, $last);
        return $date === null ? null : $this->pending = $this->candidate($date->getTimestamp());
    }

    private function nextFromFallback(bool $hasHistory): ?ExecutionOccurrenceCandidate
    {
        if ($this->scanExhausted) {
            return $this->fallbackMissedCandidate();
        }
        $now = $this->scanNow;
        $scanCursor = $this->fallbackCursor;
        if ($now === null || $scanCursor === null) {
            throw new InvalidArgumentException('Replay window was not initialized.');
        }
        for ($calls = 0; $calls < 64; ++$calls) {
            $date = $this->trigger->getNextRunDate($scanCursor);
            if ($date === null) {
                $this->scanExhausted = true;
                return $this->fallbackMissedCandidate();
            }
            $this->fallbackCursor = $scanCursor = $date;
            if ($date < $now) {
                if ($hasHistory) {
                    $this->retainMissed($date);
                }
                continue;
            }
            return $this->fallbackMissedCandidate() ?? ($this->pending = $this->candidate($date->getTimestamp()));
        }
        // The retained replay list cannot be emitted until the scan knows its newest bound.
        return null;
    }

    private function fallbackMissedCandidate(): ?ExecutionOccurrenceCandidate
    {
        if ($this->fallbackMissed === []) {
            return null;
        }
        $index = $this->policy->policy === MissedOccurrencePolicy::LAST ? count($this->fallbackMissed) - 1 : 0;
        return $this->pending = $this->candidate($this->fallbackMissed[$index]->getTimestamp());
    }

    private function retainMissed(DateTimeImmutable $date): void
    {
        $this->fallbackMissed[] = $date;
        $limit = $this->policy->policy === MissedOccurrencePolicy::LAST ? 1 : $this->policy->max;
        if ($limit === null) {
            throw new InvalidArgumentException('The all missed occurrence policy requires a positive max.');
        }
        if (count($this->fallbackMissed) > $limit) {
            $this->fallbackMissed = array_slice($this->fallbackMissed, -$limit);
        }
    }

    private function resetScan(string $cursor): void
    {
        $this->pending = null;
        $this->scanCheckpoint = $cursor;
        $this->scanNow = $this->clock->now();
        $this->fallbackCursor = self::decodeCursor($cursor);
        $this->fallbackMissed = [];
        $this->scanExhausted = false;
    }

    private function candidate(int $timestamp): ExecutionOccurrenceCandidate
    {
        $occurrenceId = 'o' . str_pad((string) $timestamp, 10, '0', STR_PAD_LEFT);
        $occurrence = new ExecutionOccurrenceKey($this->taskKey, $occurrenceId);
        $mapping = $this->bindings->map($this->taskKey, $occurrenceId, $timestamp);
        return new ExecutionOccurrenceCandidate($occurrence, $mapping, self::encodeTimestamp($timestamp));
    }

    private static function encodeTimestamp(int $timestamp): string
    {
        if ($timestamp < 0 || $timestamp > 9999999999) {
            throw new InvalidArgumentException('Occurrence timestamp is outside the portable cursor range.');
        }
        return 't' . str_pad((string) $timestamp, 10, '0', STR_PAD_LEFT);
    }

    private static function decodeCursor(string $cursor): DateTimeImmutable
    {
        if (preg_match('/^t([0-9]{10})$/D', $cursor, $matches) !== 1) {
            throw new InvalidArgumentException('Occurrence checkpoint is not a portable timestamp cursor.');
        }
        return (new DateTimeImmutable('@' . $matches[1]))->setTimezone(new DateTimeZone('UTC'));
    }
}
