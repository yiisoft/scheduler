<?php

declare(strict_types=1);

namespace Yiisoft\Scheduler\Ledger;

use Yiisoft\Scheduler\Exception\LogicException;

use function sprintf;
use function trim;

/**
 * The right to change one execution record, held by one attempt at a time.
 *
 * Every mutation names the authority it believes is current, and the ledger refuses the ones
 * that do not match. That is what keeps a planner that was replaced, or a worker that outlived
 * its planner, from writing over the attempt that took its place: the old actor is not stopped,
 * it is simply no longer believed.
 *
 * A new attempt gets a new authority, so the refusal is by value and needs no clock.
 *
 * The ledger mints them, from the identity and a number that only ever goes up, so that no two
 * attempts at one execution are ever under the same token: one a caller picked could be one an
 * earlier attempt already held, and the worker that attempt left behind would pass the check
 * again.
 *
 * It is a value a write either matches or does not, not a secret. Anything that can read the
 * ledger can read the authority a record carries, and recovery is meant to: an actor learns it
 * was replaced by being refused, not by being kept in the dark.
 */
final class ExecutionAuthority
{
    public function __construct(public readonly string $token)
    {
        if ('' === trim($token)) {
            throw new LogicException('An execution authority must not be empty.');
        }
    }

    /** The authority of the given attempt at the given execution. */
    public static function forAttempt(ExecutionIdentity $identity, int $attempt): self
    {
        if ($attempt < 1) {
            throw new LogicException(sprintf('An attempt is numbered from one, "%d" given.', $attempt));
        }

        return new self($identity->key() . '/' . $attempt);
    }

    public function equals(self $other): bool
    {
        return $this->token === $other->token;
    }
}
