<?php

declare(strict_types=1);

namespace Yiisoft\Scheduler\Integration\Async\Process;

use DateTimeImmutable;

use function strlen;
use function substr;

final class ProcessResult
{
    public const MAX_DIAGNOSTIC_LENGTH = 65536;
    public readonly string $stdout;
    public readonly string $stderr;
    public readonly bool $stdoutTruncated;
    public readonly bool $stderrTruncated;

    public function __construct(
        /** Diagnostics retain only the first MAX_DIAGNOSTIC_LENGTH bytes. */
        public readonly ?int $exitCode = null,
        public readonly ?int $signal = null,
        string $stdout = '',
        string $stderr = '',
        public readonly ?DateTimeImmutable $at = null,
        ?bool $stdoutTruncated = null,
        ?bool $stderrTruncated = null,
    ) {
        $this->stdout = substr($stdout, 0, self::MAX_DIAGNOSTIC_LENGTH);
        $this->stderr = substr($stderr, 0, self::MAX_DIAGNOSTIC_LENGTH);
        $this->stdoutTruncated = $stdoutTruncated ?? strlen($stdout) > self::MAX_DIAGNOSTIC_LENGTH;
        $this->stderrTruncated = $stderrTruncated ?? strlen($stderr) > self::MAX_DIAGNOSTIC_LENGTH;
    }
}
