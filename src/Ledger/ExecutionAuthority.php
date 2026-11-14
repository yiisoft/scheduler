<?php

declare(strict_types=1);

namespace Yiisoft\Scheduler\Ledger;

use Yiisoft\Scheduler\Exception\LogicException;

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
 */
final class ExecutionAuthority
{
    public function __construct(public readonly string $token)
    {
        if ('' === trim($token)) {
            throw new LogicException('An execution authority must not be empty.');
        }
    }

    public function equals(self $other): bool
    {
        return $this->token === $other->token;
    }
}
