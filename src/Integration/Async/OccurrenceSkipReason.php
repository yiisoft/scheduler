<?php

declare(strict_types=1);

namespace Yiisoft\Scheduler\Integration\Async;

enum OccurrenceSkipReason: string
{
    case OVERLAP = 'overlap';
    case DOWNTIME = 'downtime';
    case RANGE_TRIM = 'range-trim';

}
