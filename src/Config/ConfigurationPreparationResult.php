<?php

declare(strict_types=1);

namespace Yiisoft\Schedule\Config;

/** Result of preparation together with the fence that verified it. */
final class ConfigurationPreparationResult
{
    public function __construct(public readonly mixed $result, public readonly ConfigurationSourcePin $pin) {}
}
