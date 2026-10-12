<?php

declare(strict_types=1);

namespace Yiisoft\Scheduler\Config;

use Yiisoft\Scheduler\Trigger\TriggerInterface;

/** Backwards-compatible configuration namespace entry point. */
final class TriggerFactory
{
    public static function create(TriggerDefinition|array $descriptor): TriggerInterface
    {
        return \Yiisoft\Scheduler\Trigger\TriggerFactory::create($descriptor);
    }
}
