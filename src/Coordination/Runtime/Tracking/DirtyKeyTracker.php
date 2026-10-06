<?php

declare(strict_types=1);

namespace Ultra\Coordination\Tracking;

/**
 * A coalesced, lost-update-safe ledger of pending work.
 *
 * Producers record coarse dirty markers as `(channel, key)` pairs; a counter
 * per pair absorbs repeats. A consumer reads the pending keys, processes them
 * against current state, and then `drainSeen()`s exactly the counters it
 * observed — so markers bumped concurrently during processing are not lost.
 *
 * `channel` is the coarse partition that selects *how* work is processed;
 * `key` is the fine-grained dirty unit within it. (For a projection: channel =
 * projection id, key = stream bucket. For a derived read model: channel = the
 * kind of entity, key = the entity id.)
 *
 * This is NOT an outbox: it carries no ordering and no payload, only "this key
 * is dirty." If you need ordered or payloaded delivery, use a different
 * primitive.
 */
interface DirtyKeyTracker
{
    /**
     * Record that `$key` within `$channel` has pending work, incrementing its
     * coalescing counter.
     */
    public function bump(string $channel, string $key): void;

    /**
     * The busiest pending keys across all channels (counter descending),
     * capped at `$limit`. Used to decide where work is waiting.
     *
     * @return list<PendingKey>
     */
    public function pending(int $limit = 256): array;

    /**
     * Pending keys within a single channel (counter descending), capped at
     * `$limit`.
     *
     * @return list<PendingKey>
     */
    public function pendingForChannel(string $channel, int $limit = 256): array;

    /**
     * Subtract the observed counters from the live ones, flooring at zero.
     *
     * `$seen` maps key => the counter value the caller observed before
     * processing. Concurrent bumps that happened after observation remain,
     * leaving the key pending for the next cycle. Returns the number of keys
     * touched.
     *
     * @param array<string, int> $seen
     */
    public function drainSeen(string $channel, array $seen): int;
}
