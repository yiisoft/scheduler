<?php

declare(strict_types=1);

namespace Yiisoft\Schedule\Config;

enum MissedOccurrencePolicy: string
{
    case SKIP = 'skip';
    case ALL = 'all';
    case LAST = 'last';
}
