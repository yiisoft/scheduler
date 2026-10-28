<?php

declare(strict_types=1);

namespace Yiisoft\Scheduler\Integration\Async\Recovery;

use InvalidArgumentException;
use Yiisoft\Scheduler\Integration\Async\{ExecutionLedgerRecord, ExecutionStartObservation};

use function count;
use function is_array;
use function is_finite;
use function is_float;
use function is_int;
use function intdiv;
use function ord;
use function strlen;
use function sprintf;
use function str_repeat;
use function str_starts_with;
use function strcmp;
use function str_pad;
use function substr;
use function trim;

use const PHP_INT_MAX;

/** Exact wall-clock recovery deadline, expressed as integer microseconds. */
final class RecoveryDeadline
{
    public static function forRecord(ExecutionLedgerRecord $record): ?int
    {
        if ($record->startObservation === null) {
            return null;
        }
        return $record->recoveryDeadline ?? self::calculate($record, $record->startObservation);
    }

    public static function forStart(ExecutionLedgerRecord $record, ExecutionStartObservation $start): ?int
    {
        return self::calculate($record, $start);
    }

    /** Convert seconds to a non-early integer microsecond duration. */
    public static function durationMicroseconds(mixed $seconds, bool $allowZero = false): int
    {
        if ((!is_int($seconds) && !is_float($seconds)) || !is_finite((float) $seconds)
            || ($allowZero ? $seconds < 0 : $seconds <= 0)) {
            throw new InvalidArgumentException('Recovery duration is invalid.');
        }
        // Use an explicit round-trip precision: the default PHP precision may
        // round a value just above a microsecond boundary down before parsing.
        $text = is_int($seconds) ? (string) $seconds : strtolower(sprintf('%.17g', $seconds));
        if (!preg_match('/^(\d+)(?:\.(\d+))?(?:e([+-]?\d+))?$/', $text, $m)) {
            throw new InvalidArgumentException('Recovery duration is invalid.');
        }
        $digits = ltrim($m[1] . ($m[2] ?? ''), '0');
        $digits = $digits === '' ? '0' : $digits;
        if ($digits === '0') {
            if (!$allowZero) {
                throw new InvalidArgumentException('Recovery duration is invalid.');
            }
            return 0;
        }

        // Do not let an unbounded exponent become an unbounded string operation.
        $exponentText = $m[3] ?? '0';
        $negativeExponent = str_starts_with($exponentText, '-');
        $exponentDigits = ltrim(ltrim($exponentText, '+-'), '0');
        if ($exponentDigits === '') {
            $exponent = 0;
        } elseif (strlen($exponentDigits) > 9) {
            if ($negativeExponent) {
                return 1;
            }
            throw new InvalidArgumentException('Recovery duration overflows integer microseconds.');
        } else {
            $exponent = (int) $exponentDigits * ($negativeExponent ? -1 : 1);
        }
        $scale = strlen($m[2] ?? '') - $exponent;
        if ($scale < -13) {
            throw new InvalidArgumentException('Recovery duration overflows integer microseconds.');
        }
        if ($scale > strlen($digits) + 6) {
            // A positive non-zero value this small still ceils to one microsecond.
            return 1;
        }
        if ($scale < 0) {
            $digits .= str_repeat('0', -$scale);
            $scale = 0;
        }
        $position = strlen($digits) - $scale;
        if ($position <= 0) {
            $whole = '0';
            $fraction = str_repeat('0', -$position) . $digits;
        } else {
            $whole = substr($digits, 0, $position);
            $fraction = substr($digits, $position);
        }
        $micro = (int) str_pad(substr($fraction . '000000', 0, 6), 6, '0');
        if ($scale > 6 && trim(substr($fraction, 6), '0') !== '') {
            ++$micro;
        }
        if ($micro === 1000000) {
            $whole = self::incrementDecimal($whole);
            $micro = 0;
        }
        // Compare as decimal text: casting an oversized whole value can overflow
        // before the invalid/overflow exception is reached.
        if (strlen($whole) > 13 || (strlen($whole) === 13 && strcmp($whole, '9223372036854') > 0)
            || ($whole === '9223372036854' && $micro > 775807)) {
            throw new InvalidArgumentException('Recovery duration overflows integer microseconds.');
        }
        $result = ((int) $whole * 1000000) + $micro;
        if ($result < 0 || ($result === 0 && !$allowZero)) {
            throw new InvalidArgumentException('Recovery duration is invalid.');
        }
        return $result;
    }

    private static function calculate(ExecutionLedgerRecord $record, ExecutionStartObservation $start): ?int
    {
        $task = $record->taskSnapshot['task'] ?? null;
        if ($task !== null && !is_array($task)) {
            throw new InvalidArgumentException('Recovery task policy is invalid.');
        }
        $policyAge = is_array($task) ? ($task['recoveryMaxAge'] ?? null) : null;
        $age = $record->recoveryAge ?? $policyAge;
        if ($record->recoveryAge !== null && $policyAge !== null
            && self::durationMicroseconds($record->recoveryAge) !== self::durationMicroseconds($policyAge)) {
            throw new InvalidArgumentException('Persisted recovery age does not match the recovery policy.');
        }
        $timeout = is_array($task) ? ($task['timeout'] ?? null) : null;
        $hardOrSoft = 0;
        if ($timeout !== null) {
            if (!is_array($timeout) || count($timeout) !== 2) {
                throw new InvalidArgumentException('Recovery timeout is invalid.');
            }
            $seconds = $timeout[1] ?? $timeout[0] ?? 0;
            $hardOrSoft = self::durationMicroseconds($seconds, true);
        }
        if ($age === null) {
            return null;
        }
        $window = max(self::durationMicroseconds($age), $hardOrSoft);
        return self::checkedAdd($start->wallMicroseconds, $window);
    }

    private static function incrementDecimal(string $value): string
    {
        $result = '';
        $carry = 1;
        for ($index = strlen($value) - 1; $index >= 0; --$index) {
            $digit = ord($value[$index]) - ord('0') + $carry;
            $result = (string) ($digit % 10) . $result;
            $carry = intdiv($digit, 10);
        }
        return $carry === 0 ? $result : (string) $carry . $result;
    }

    private static function checkedAdd(int $left, int $right): int
    {
        if ($right > PHP_INT_MAX - $left) {
            throw new InvalidArgumentException('Recovery deadline overflows integer microseconds.');
        }
        return $left + $right;
    }
}
