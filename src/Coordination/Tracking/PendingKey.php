<?php

declare(strict_types=1);

namespace Ultra\Coordination\Tracking;

/**
 * A coarse "this key is dirty" marker: one fine-grained unit of pending work
 * within a channel, with the coalesced counter of how many times it has been
 * bumped since it was last drained.
 *
 * The counter exists for two reasons: it lets readers prioritize busier keys,
 * and it is the basis of the lost-update-safe drain — a drainer subtracts
 * exactly the counter value it observed, so bumps that land mid-drain survive.
 */
final readonly class PendingKey
{
    public function __construct(
        public string $channel,
        public string $key,
        public int $counter,
    ) {}
}
