<?php

declare(strict_types=1);

namespace Ultra\Projection\Coordination;

use Ultra\Coordination\Tracking\DirtyKeyTracker;
use Ultra\Coordination\Tracking\PendingKey;

/**
 * Projection-topology view over the generic dirty-key tracker.
 *
 * Encodes the one piece of knowledge that is specific to projections: a
 * projection's stream is partitioned into integer buckets (0..255), and each
 * bucket maps onto a generic tracker key within the projection's channel
 * (channel = projection id, key = stringified bucket). Segment reads are an
 * in-memory range filter over the channel's pending keys — the bucket space is
 * fixed, so the pending set per projection is small.
 *
 * There is deliberately no interface: it composes whatever DirtyKeyTracker is
 * injected, so the same class serves both in-memory and Postgres runtimes.
 */
final readonly class ProjectionActivity
{
    public function __construct(
        private DirtyKeyTracker $tracker,
    ) {}

    public function bump(string $projectionId, int $bucket): void
    {
        $this->tracker->bump($projectionId, (string) $bucket);
    }

    /**
     * Pending buckets across all projections, ordered for the coordinator
     * (projection id asc, counter desc, bucket asc), capped at `$limit`.
     *
     * @return list<PendingKey>
     */
    public function affected(int $limit = 256): array
    {
        $affected = $this->tracker->pending(PHP_INT_MAX);

        usort($affected, static function (PendingKey $a, PendingKey $b): int {
            $projectionOrder = $a->channel <=> $b->channel;
            if (0 !== $projectionOrder) {
                return $projectionOrder;
            }
            $counterOrder = $b->counter <=> $a->counter;
            if (0 !== $counterOrder) {
                return $counterOrder;
            }

            return (int) $a->key <=> (int) $b->key;
        });

        return array_slice($affected, 0, $limit);
    }

    /**
     * Pending buckets for a projection within a segment's bucket range,
     * ordered (counter desc, bucket asc).
     *
     * @return list<PendingKey>
     */
    public function pendingInSegment(string $projectionId, int $bucketFrom, int $bucketTo): array
    {
        $pending = [];
        foreach ($this->tracker->pendingForChannel($projectionId, PHP_INT_MAX) as $pendingKey) {
            $bucket = (int) $pendingKey->key;
            if ($bucket < $bucketFrom || $bucket > $bucketTo) {
                continue;
            }
            $pending[] = $pendingKey;
        }

        usort($pending, static function (PendingKey $a, PendingKey $b): int {
            $counterOrder = $b->counter <=> $a->counter;
            if (0 !== $counterOrder) {
                return $counterOrder;
            }

            return (int) $a->key <=> (int) $b->key;
        });

        return $pending;
    }

    /**
     * Drain the observed counters for a projection's buckets.
     *
     * @param array<int, int> $seenByBucket bucket => observed counter
     */
    public function drainSeen(string $projectionId, array $seenByBucket): int
    {
        $seen = [];
        foreach ($seenByBucket as $bucket => $counter) {
            $seen[(string) $bucket] = $counter;
        }

        return $this->tracker->drainSeen($projectionId, $seen);
    }
}
