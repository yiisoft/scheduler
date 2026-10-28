<?php

declare(strict_types=1);

namespace Yiisoft\Scheduler\Integration\Async;

use InvalidArgumentException;

use function bin2hex;
use function preg_match;
use function random_bytes;
use function strlen;
use function in_array;

/** Bounded, non-secret identity written before an external worker is started. */
final class ExecutionLaunchDescriptor
{
    public const SCOPE_KIND = 'linux-setsid-wait';
    public const ROLE_WORKER = 'worker';
    public const ROLE_COMMAND = 'command';
    public const WORKER_MARKER = 'YII_SCHEDULER_WORKER_MARKER';
    public const COMMAND_MARKER = 'YII_SCHEDULER_COMMAND_MARKER';
    public readonly string $nonce;

    public function __construct(
        public readonly string $executionId,
        public readonly int $generation,
        ?string $nonce = null,
        public readonly string $scopeKind = self::SCOPE_KIND,
        public readonly string $role = self::ROLE_WORKER,
        public readonly ?ExecutionLaunchDescriptor $rootDescriptor = null,
        public readonly ?ExecutionOccurrenceKey $occurrence = null,
    ) {
        $this->nonce = $nonce ?? bin2hex(random_bytes(16));
        foreach ([$executionId, $this->nonce, $scopeKind] as $value) {
            if ($value === '' || strlen($value) > 256 || preg_match('/^[A-Za-z0-9._:-]+$/D', $value) !== 1) {
                throw new InvalidArgumentException('Launch descriptor contains an invalid value.');
            }
        }
        if ($generation < 1) {
            throw new InvalidArgumentException('Launch descriptor generation is invalid.');
        }
        if (!in_array($this->role, [self::ROLE_WORKER, self::ROLE_COMMAND], true)) {
            throw new InvalidArgumentException('Launch descriptor role is invalid.');
        }
        if ($this->role === self::ROLE_WORKER && $rootDescriptor !== null) {
            throw new InvalidArgumentException('Worker launch descriptor cannot have a root binding.');
        }
        if ($this->role === self::ROLE_COMMAND) {
            if ($rootDescriptor === null || $occurrence === null || $rootDescriptor->role !== self::ROLE_WORKER || $rootDescriptor->occurrence === null) {
                throw new InvalidArgumentException('Command launch descriptor requires a bound worker root.');
            }
            if ($rootDescriptor->scopeKind !== self::SCOPE_KIND || $rootDescriptor->executionId !== $executionId || $rootDescriptor->generation !== $generation || !$occurrence->equals($rootDescriptor->occurrence)) {
                throw new InvalidArgumentException('Command launch descriptor binding does not match the current execution occurrence.');
            }
        }
    }

    public function marker(): string
    {
        // Include every binding fact in the process marker so bound and
        // unbound observations cannot be confused with a launch descriptor.
        $prefix = $this->role === self::ROLE_COMMAND ? self::ROLE_COMMAND . ':' : '';
        $occurrence = $this->occurrence === null ? 'unbound' : (string) $this->occurrence;
        $root = $this->rootDescriptor === null ? 'none' : $this->rootDescriptor->marker();
        return $prefix . $this->scopeKind . ':' . $this->executionId . ':' . $this->generation . ':' . $this->nonce . ':' . $occurrence . ':' . $root;
    }

    public function equals(self $other): bool
    {
        return $this->executionId === $other->executionId
            && $this->generation === $other->generation
            && $this->nonce === $other->nonce
            && $this->scopeKind === $other->scopeKind
            && $this->role === $other->role
            && (($this->occurrence === null && $other->occurrence === null)
                || ($this->occurrence !== null && $other->occurrence !== null && $this->occurrence->equals($other->occurrence)))
            && (($this->rootDescriptor === null && $other->rootDescriptor === null)
                || ($this->rootDescriptor !== null && $other->rootDescriptor !== null && $this->rootDescriptor->equals($other->rootDescriptor)));
    }

    public function markerName(): string
    {
        return $this->role === self::ROLE_WORKER ? self::WORKER_MARKER : self::COMMAND_MARKER;
    }
}
