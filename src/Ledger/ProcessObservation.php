<?php

declare(strict_types=1);

namespace Yiisoft\Scheduler\Ledger;

use Yiisoft\Scheduler\Exception\LogicException;

use function sprintf;

/**
 * What was seen of a process that is no longer running.
 *
 * Kept with the release that it explains, so that a record says not only that it gave up a
 * process but on what evidence. A process that exited with a status and one that was killed by
 * a signal are different things to a human reading a backlog afterwards, and neither of them
 * says what the work achieved: {@see ExecutionOutcome} is the only thing that does.
 *
 * Both facts are optional because a process can be known to be gone without either: a pid that
 * no longer exists, observed by something that never waited on it, is an honest observation
 * with nothing in it but the absence.
 */
final class ProcessObservation
{
    public function __construct(
        /** The status the process exited with, where something waited on it to learn that. */
        public readonly ?int $exitCode = null,
        /** The signal that ended it, where it was ended by one. */
        public readonly ?int $signal = null,
    ) {
        foreach (['exit code' => $exitCode, 'signal' => $signal] as $name => $value) {
            if (null !== $value && $value < 0) {
                throw new LogicException(sprintf(
                    'The %s of a process observation must not be negative, "%d" given.',
                    $name,
                    $value,
                ));
            }
        }
    }
}
