<?php

declare(strict_types=1);

use ShipMonk\ComposerDependencyAnalyser\Config\Configuration;
use ShipMonk\ComposerDependencyAnalyser\Config\ErrorType;

return (new Configuration())
    ->disableComposerAutoloadPathScan()
    ->setFileExtensions(['php'])
    ->addPathToScan(__DIR__ . '/src', isDev: false)
    ->addPathToScan(__DIR__ . '/tests', isDev: true)
    // The default SQLite adapter is runtime-optional: custom command/query
    // ports and explicit worker locators remain usable without these packages.
    // Production code checks their extension/classes before default composition.
    ->ignoreErrorsOnPackages(
        ['yiisoft/db', 'yiisoft/db-sqlite'],
        [ErrorType::DEV_DEPENDENCY_IN_PROD],
    )
    // ctype is covered by Symfony's polyfill when unavailable; POSIX is an
    // optional capability checked at runtime by the owned-process adapter.
    ->ignoreErrorsOnExtensions(['ext-ctype', 'ext-posix'], [ErrorType::SHADOW_DEPENDENCY]);
