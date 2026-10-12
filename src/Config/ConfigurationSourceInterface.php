<?php

declare(strict_types=1);

namespace Yiisoft\Scheduler\Config;

/** A configuration source with a stable identity and a pinned revision. */
interface ConfigurationSourceInterface
{
    /** Canonical identity of the source; this is not its revision. */
    public function sourceId(): string;

    /** Content revision. Implementations must return a bounded, non-empty value. */
    public function revision(): string;
}
