<?php

declare(strict_types=1);

namespace Yiisoft\Schedule;

enum ExecutionMode: string
{
    case SYNC = 'sync';
    case ASYNC = 'async';
}
