<?php

declare(strict_types=1);

namespace Yiisoft\Scheduler\Config;

use InvalidArgumentException;
use Throwable;

use function strlen;

/** Pins a source revision around preparation and refuses an inconsistent result. */
final class ConfigurationVersionGuard
{
    public const MAX_REVISION_LENGTH = 128;

    /**
     * Prepare against one verified source version.
     *
     * @param callable(): mixed $prepare
     * @return ConfigurationPreparationResult
     */
    public function execute(ConfigurationSourceInterface $source, callable $prepare): ConfigurationPreparationResult
    {
        $sourceId = $this->sourceId($source->sourceId());
        $before = $this->revision($source->revision());
        if ($before !== $this->revision($source->revision())) {
            throw new InvalidArgumentException('Configuration source revision is unstable.');
        }
        try {
            $result = $prepare();
        } catch (Throwable $exception) {
            throw $exception;
        }
        $after = $this->revision($source->revision());
        if ($before !== $after) {
            throw new InvalidArgumentException('Configuration source changed during preparation.');
        }
        // Reading the identity after preparation also protects adapters whose
        // backing source can be replaced without changing the revision format.
        if ($sourceId !== $this->sourceId($source->sourceId())) {
            throw new InvalidArgumentException('Configuration source identity changed during preparation.');
        }

        // Return the value that was fenced by this preparation, rather than
        // making callers reuse a pin obtained before the fence was established.
        return new ConfigurationPreparationResult($result, new ConfigurationSourcePin($sourceId, $after));
    }

    public function pin(ConfigurationSourceInterface $source): ConfigurationSourcePin
    {
        $sourceId = $this->sourceId($source->sourceId());
        $revision = $this->revision($source->revision());
        if ($revision !== $this->revision($source->revision())) {
            throw new InvalidArgumentException('Configuration source revision is unstable.');
        }
        return new ConfigurationSourcePin($sourceId, $revision);
    }

    private function revision(string $value): string
    {
        if ($value === '' || strlen($value) > self::MAX_REVISION_LENGTH) {
            throw new InvalidArgumentException('Configuration source revision is empty or too long.');
        }
        return $value;
    }

    private function sourceId(string $value): string
    {
        if ($value === '' || strlen($value) > 4096) {
            throw new InvalidArgumentException('Configuration source identity is empty or too long.');
        }
        return $value;
    }
}
