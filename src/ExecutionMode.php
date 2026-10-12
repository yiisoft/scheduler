<?php

declare(strict_types=1);

namespace Yiisoft\Scheduler;

enum ExecutionMode: string
{
    case SYNC = 'sync';
    case ASYNC = 'async';
}
