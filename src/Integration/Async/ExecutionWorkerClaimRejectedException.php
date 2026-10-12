<?php

declare(strict_types=1);

namespace Yiisoft\Scheduler\Integration\Async;

use LogicException;

/** Indicates that worker authority was not established by the ledger. */
final class ExecutionWorkerClaimRejectedException extends LogicException {}
