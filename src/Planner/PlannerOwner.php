<?php

declare(strict_types=1);

namespace Yiisoft\Schedule\Planner;

use RuntimeException;

use const DIRECTORY_SEPARATOR;
use const LOCK_UN;

/** A held local ownership lease. Its open handle is the ownership proof. */
final class PlannerOwner implements PlannerOwnerInterface
{
    public const MARKER_FILE = 'planner-owner';

    /** @var resource|null */
    private $handle;

    /** @param resource $handle */
    public function __construct(
        private readonly string $source,
        private readonly string $token,
        private readonly string $directory,
        $handle,
    ) {
        $this->handle = $handle;
    }

    public function __destruct()
    {
        $this->release();
    }

    public function sourceId(): string
    {
        return $this->source;
    }

    public function ownerToken(): string
    {
        return $this->token;
    }

    public function stateDirectory(): string
    {
        return $this->directory;
    }

    public function release(): void
    {
        if ($this->handle === null) {
            return;
        }

        $handle = $this->handle;
        $this->handle = null;
        // Invalidate while the lock is still held, so a new owner cannot have
        // its marker removed by this lease's release.
        @unlink($this->directory . DIRECTORY_SEPARATOR . self::MARKER_FILE);
        $released = flock($handle, LOCK_UN);
        fclose($handle);
        if (!$released) {
            throw new RuntimeException('Planner owner lock could not be released.');
        }
    }
}
