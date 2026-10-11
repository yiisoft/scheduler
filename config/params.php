<?php

declare(strict_types=1);

use Yiisoft\Scheduler\Command\ListCommand;
use Yiisoft\Scheduler\Command\RunCommand;
use Yiisoft\Scheduler\Command\WorkCommand;

return [
    'yiisoft/yii-console' => [
        'commands' => [
            'schedule:run' => RunCommand::class,
            'schedule:work' => WorkCommand::class,
            'schedule:list' => ListCommand::class,
        ],
    ],
    'yiisoft/scheduler' => [
        'tasks' => [],
    ],
];
