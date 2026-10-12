<?php

declare(strict_types=1);

namespace Yiisoft\Scheduler\Config;

use Yiisoft\Scheduler\Executor\CommandExecutorRuntimeFactory;

/** Configuration source which can produce prepared process-local configuration. */
interface RuntimeConfigurationSourceInterface extends ConfigurationSourceInterface
{
    public function prepare(CommandExecutorRuntimeFactory $runtimeFactory): PreparedConfiguration;
}
