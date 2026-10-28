<?php

declare(strict_types=1);

namespace Yiisoft\Scheduler\Integration\Async;

use InvalidArgumentException;

use function bin2hex;
use function in_array;
use function random_bytes;
use function strlen;

/** Durable reservation made before a dispatcher attempts a launch. */
final class ExecutionDispatchReservation
{
    public const PREPARED = 'prepared';
    public const STARTING = 'starting';
    public const OBSERVED = 'observed';
    public const AMBIGUOUS = 'ambiguous';

    public readonly string $permissionId;
    public readonly string $launchPhase;

    public function __construct(
        public readonly string $dispatcherId,
        public readonly int $reservedAt,
        public readonly int $deadline,
        ?string $permissionId = null,
        string $launchPhase = self::PREPARED,
    ) {
        $this->permissionId = $permissionId ?? bin2hex(random_bytes(16));
        $this->launchPhase = $launchPhase;
        if ($dispatcherId === '' || strlen($dispatcherId) > 255 || preg_match('/[\x00-\x1F\x7F]/', $dispatcherId) === 1 || $reservedAt < 0 || $deadline < $reservedAt
            || preg_match('/^[a-f0-9]{32}$/D', $this->permissionId) !== 1
            || !in_array($launchPhase, [self::PREPARED, self::STARTING, self::OBSERVED, self::AMBIGUOUS], true)) {
            throw new InvalidArgumentException('Dispatch reservation facts are invalid.');
        }
    }

    public function withPhase(string $phase): self
    {
        $allowed = match ($this->launchPhase) {
            self::PREPARED => [self::PREPARED, self::STARTING, self::AMBIGUOUS],
            self::STARTING => [self::STARTING, self::AMBIGUOUS, self::OBSERVED],
            self::AMBIGUOUS => [self::AMBIGUOUS, self::OBSERVED],
            self::OBSERVED => [self::OBSERVED],
        };
        if (!in_array($phase, $allowed, true)) {
            throw new InvalidArgumentException('Dispatch reservation phase transition is invalid.');
        }
        return new self($this->dispatcherId, $this->reservedAt, $this->deadline, $this->permissionId, $phase);
    }
}
