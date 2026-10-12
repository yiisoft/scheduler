<?php

declare(strict_types=1);

namespace Yiisoft\Scheduler\Config;

use Closure;
use InvalidArgumentException;
use ReflectionFunction;

use function is_file;

/** Loads a PHP source file containing a zero-argument SchedulerConfig factory. */
final class PhpConfigurationLoader
{
    public function loadFile(string $path): SchedulerConfig
    {
        if (!is_file($path)) {
            throw new InvalidArgumentException('Configuration source file does not exist.');
        }

        $factory = require $path;
        if (!$factory instanceof Closure) {
            throw new InvalidArgumentException('Configuration PHP file must return a zero-argument factory.');
        }
        if ((new ReflectionFunction($factory))->getNumberOfParameters() !== 0) {
            throw new InvalidArgumentException('Configuration factory must accept no arguments.');
        }

        $configuration = $factory();
        if (!$configuration instanceof SchedulerConfig) {
            throw new InvalidArgumentException('Configuration factory must return SchedulerConfig.');
        }

        return $configuration;
    }
}
