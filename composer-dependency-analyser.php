<?php

declare(strict_types=1);

use ShipMonk\ComposerDependencyAnalyser\Config\Configuration;
use ShipMonk\ComposerDependencyAnalyser\Config\ErrorType;

return (new Configuration())
    ->disableComposerAutoloadPathScan()
    ->setFileExtensions(['php'])
    ->addPathToScan(__DIR__ . '/config', isDev: false)
    ->addPathToScan(__DIR__ . '/src', isDev: false)
    ->addPathToScan(__DIR__ . '/tests', isDev: true)
    // Optional integrations, guarded at runtime and listed in "suggest".
    ->ignoreErrorsOnPackages(['symfony/console', 'yiisoft/mutex', 'yiisoft/queue'], [ErrorType::DEV_DEPENDENCY_IN_PROD])
    ->ignoreErrorsOnExtensions(['ext-pcntl', 'ext-posix'], [ErrorType::SHADOW_DEPENDENCY]);
