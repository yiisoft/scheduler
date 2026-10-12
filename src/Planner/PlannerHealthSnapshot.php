<?php

declare(strict_types=1);

namespace Yiisoft\Schedule\Planner;

use Yiisoft\Schedule\Config\ConfigurationSourceInterface;
use InvalidArgumentException;
use RuntimeException;
use Throwable;

use function function_exists;
use function is_array;
use function is_int;
use function is_string;
use function strlen;

use const DIRECTORY_SEPARATOR;
use const JSON_THROW_ON_ERROR;
use const JSON_UNESCAPED_SLASHES;
use const LOCK_EX;
use const LOCK_NB;
use const LOCK_SH;

/**
 * The small, scalar liveness document for a source planner.
 *
 * Progress is considered recent for the configured threshold. Health probes
 * never create, lock, or rewrite any state; the planner is the only writer.
 */
final class PlannerHealthSnapshot
{
    public const FILE = 'planner-health.json';
    public const DEFAULT_MAX_AGE = 15;
    public const MAX_ERROR_LENGTH = 1024;
    public const PHASE_RUNNING = 'running';
    public const PHASE_STOPPING = 'stopping';
    public const PHASE_FAILED = 'failed';

    /** @var list<string> */
    private const FIELDS = ['sourceId', 'sourceRevision', 'ownerToken', 'ownerPid', 'startedAt', 'progressAt', 'phase', 'error'];

    private readonly ?string $error;

    public function __construct(
        private readonly string $sourceId,
        private readonly string $revision,
        private readonly string $ownerToken,
        private readonly int $ownerPid,
        private readonly int $startedAt,
        private readonly int $progressAt,
        private readonly string $phase = self::PHASE_RUNNING,
        ?string $error = null,
    ) {
        $this->error = $error === null ? null : substr($error, 0, self::MAX_ERROR_LENGTH);
    }

    public function write(string $directory): void
    {
        $data = [
            'sourceId' => $this->sourceId, 'sourceRevision' => $this->revision,
            'ownerToken' => $this->ownerToken, 'ownerPid' => $this->ownerPid,
            'startedAt' => $this->startedAt, 'progressAt' => $this->progressAt,
            'phase' => $this->phase, 'error' => $this->error,
        ];
        $temporary = $directory . DIRECTORY_SEPARATOR . '.' . self::FILE . '.' . bin2hex(random_bytes(6));
        $encoded = json_encode($data, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
        if (file_put_contents($temporary, $encoded, LOCK_EX) === false || !rename($temporary, $directory . DIRECTORY_SEPARATOR . self::FILE)) {
            @unlink($temporary);
            throw new RuntimeException('Planner health snapshot cannot be written.');
        }
    }

    /** Return null when healthy, otherwise a stable, secret-free reason. */
    public static function probe(
        ConfigurationSourceInterface $source,
        string $stateRoot,
        int $now,
        int $maxAge = self::DEFAULT_MAX_AGE,
    ): ?string {
        if ($maxAge < 1) {
            throw new InvalidArgumentException('Planner health maximum age must be greater than zero.');
        }

        $sourceId = $source->sourceId();
        $namespace = hash('sha256', $sourceId);
        $lockPath = $stateRoot . DIRECTORY_SEPARATOR . '.planner-' . $namespace . '.lock';
        $directory = $stateRoot . DIRECTORY_SEPARATOR . 'sources' . DIRECTORY_SEPARATOR . $namespace;
        $snapshotPath = $directory . DIRECTORY_SEPARATOR . self::FILE;

        if (!is_file($lockPath)) {
            return 'planner.owner_absent';
        }
        $lock = @fopen($lockPath, 'rb');
        if ($lock === false) {
            return 'planner.owner_absent';
        }
        // An exclusive lock held by another process is the ownership proof.
        $held = !flock($lock, LOCK_SH | LOCK_NB);
        fclose($lock);
        if (!$held) {
            return 'planner.owner_absent';
        }

        $formatStatus = PlannerStateFormat::healthStatus($directory);
        if ($formatStatus === 'missing') {
            return 'planner.state_format_missing';
        }
        if ($formatStatus !== 'ok') {
            return 'planner.state_format_incompatible';
        }

        if (!is_file($snapshotPath)) {
            return 'planner.snapshot_missing';
        }
        $raw = @file_get_contents($snapshotPath);
        $data = is_string($raw) ? json_decode($raw, true) : null;
        if (!is_array($data)) {
            return 'planner.snapshot_malformed';
        }
        /** @var array<string, mixed> $data */
        if (array_keys($data) !== self::FIELDS || !self::valid($data)) {
            return 'planner.snapshot_malformed';
        }
        /** @var array{sourceId: string, sourceRevision: string, ownerToken: string, ownerPid: int, startedAt: int, progressAt: int, phase: string, error: ?string} $data */
        try {
            $currentRevision = $source->revision();
        } catch (Throwable) {
            return 'planner.source_stale';
        }
        if ($data['sourceId'] !== $sourceId || $data['sourceRevision'] !== $currentRevision) {
            return 'planner.source_stale';
        }
        if (!self::markerMatches($directory, $data['ownerToken'], $data['ownerPid'])) {
            return 'planner.owner_absent';
        }
        if ($data['phase'] !== 'running') {
            return 'planner.not_running';
        }
        if ($data['error'] !== null) {
            return 'planner.error';
        }
        if ($data['progressAt'] > $now || $now - $data['progressAt'] > $maxAge) {
            return 'planner.snapshot_stale';
        }
        if (!self::livePid($data['ownerPid'])) {
            return 'planner.owner_absent';
        }
        return null;
    }

    /** @param array<string,mixed> $data */
    private static function valid(array $data): bool
    {
        return is_string($data['sourceId']) && $data['sourceId'] !== '' && strlen($data['sourceId']) <= 4096
            && is_string($data['sourceRevision']) && $data['sourceRevision'] !== '' && strlen($data['sourceRevision']) <= 256
            && is_string($data['ownerToken']) && preg_match('/^owner-[a-f0-9]{32}$/', $data['ownerToken']) === 1
            && is_int($data['ownerPid']) && $data['ownerPid'] > 0
            && is_int($data['startedAt']) && is_int($data['progressAt'])
            && is_string($data['phase']) && strlen($data['phase']) <= 32
            && ($data['error'] === null || (is_string($data['error']) && strlen($data['error']) <= 1024));
    }

    private static function markerMatches(string $directory, string $token, int $pid): bool
    {
        $raw = @file_get_contents($directory . DIRECTORY_SEPARATOR . PlannerOwner::MARKER_FILE);
        if (!is_string($raw) || strlen($raw) > 128) {
            return false;
        }
        if (preg_match('/^(owner-[a-f0-9]{32})\\n([1-9][0-9]*)\\n$/D', $raw, $matches) !== 1) {
            return false;
        }

        return $matches[1] === $token && (int) $matches[2] === $pid;
    }

    private static function livePid(int $pid): bool
    {
        if (function_exists('posix_kill')) {
            return posix_kill($pid, 0);
        }
        return is_dir('/proc/' . $pid);
    }
}
