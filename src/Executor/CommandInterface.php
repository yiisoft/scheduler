<?php

declare(strict_types=1);

namespace Yiisoft\Scheduler\Executor;

use Stringable;

/**
 * What a task runs, said once and in full.
 *
 * A command is a value: immutable, local to the process that built it, and carrying everything
 * needed to run the thing it names. It is not the thing itself, so holding one does not mean
 * being able to run it; an {@see ExecutorInterface} registered for this exact class does that.
 *
 * A command is told apart from another by its description, the way a trigger is. The identifier
 * of a task is generated from it, so the description has to name everything that decides what
 * the command runs and nothing that does not: two commands reading the same way are one task to
 * a checkpoint, and a description that changes with something harmless orphans the runs already
 * recorded under it.
 */
interface CommandInterface extends Stringable {}
