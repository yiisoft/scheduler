<?php

declare(strict_types=1);

namespace Yiisoft\Scheduler\Planner;

use InvalidArgumentException;
use RuntimeException;
use Throwable;

use function hash;
use function is_dir;
use function is_string;
use function mkdir;
use function random_bytes;
use function realpath;
use function strlen;

use const DIRECTORY_SEPARATOR;
use const LOCK_EX;
use const LOCK_NB;
use const LOCK_UN;

/** Acquires one non-blocking local lock per stable source identity. */
final class FilePlannerOwner
{
    private readonly string $root;

    public function __construct(string $stateRoot)
    {
        if ($stateRoot === '' || str_contains($stateRoot, "\0")) {
            throw new InvalidArgumentException('Planner state root is invalid.');
        }
        if (!is_dir($stateRoot) && !mkdir($stateRoot, 0700, true) && !is_dir($stateRoot)) {
            throw new InvalidArgumentException('Planner state root cannot be created.');
        }

        $root = realpath($stateRoot);
        if (!is_string($root) || !is_dir($root)) {
            throw new InvalidArgumentException('Planner state root is not a directory.');
        }
        $this->root = $root;
    }

    /**
     * The revision is intentionally not part of ownership or the state namespace.
     */
    public function acquire(string $sourceId, ?string $sourceRevision = null): PlannerOwnerInterface
    {
        $this->validateSourceId($sourceId);
        $namespace = hash('sha256', $sourceId);
        $lockPath = $this->root . DIRECTORY_SEPARATOR . '.planner-' . $namespace . '.lock';
        $handle = fopen($lockPath, 'c+b');
        if ($handle === false) {
            throw new RuntimeException('Planner owner lock cannot be opened.');
        }
        if (!flock($handle, LOCK_EX | LOCK_NB)) {
            fclose($handle);
            throw new RuntimeException('Planner source is already owned.');
        }

        try {
            $directory = $this->root . DIRECTORY_SEPARATOR . 'sources' . DIRECTORY_SEPARATOR . $namespace;
            if (!is_dir($directory) && !mkdir($directory, 0700, true) && !is_dir($directory)) {
                throw new RuntimeException('Planner source state directory cannot be created.');
            }
            // The format fence is checked before any owner marker is created.
            PlannerStateFormat::initializeOrValidate($directory);
            $token = 'owner-' . bin2hex(random_bytes(16));
            $pid = (int) getmypid();
            $marker = $directory . DIRECTORY_SEPARATOR . PlannerOwner::MARKER_FILE;
            $temporary = $marker . '.' . bin2hex(random_bytes(6)) . '.tmp';
            $contents = $token . "\n" . $pid . "\n";
            if (file_put_contents($temporary, $contents, LOCK_EX) === false || !rename($temporary, $marker)) {
                @unlink($temporary);
                throw new RuntimeException('Planner owner marker cannot be written.');
            }

            return new PlannerOwner($sourceId, $token, $directory, $handle);
        } catch (Throwable $e) {
            flock($handle, LOCK_UN);
            fclose($handle);
            throw $e;
        }
    }

    private function validateSourceId(string $sourceId): void
    {
        if ($sourceId === '' || strlen($sourceId) > 4096 || preg_match('/[\x00-\x1F\x7F]/', $sourceId) === 1) {
            throw new InvalidArgumentException('Planner source ID is invalid.');
        }
    }
}
