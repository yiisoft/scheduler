<?php

declare(strict_types=1);

namespace Yiisoft\Scheduler\Config;

enum MissedOccurrencePolicy: string
{
    case SKIP = 'skip';
    case ALL = 'all';
    case LAST = 'last';
}
