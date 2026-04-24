<?php

declare(strict_types=1);

namespace Ultra\Projection\InMemory;

use Ultra\Projection\Coordination\ProjectionBucketActivity;
use Ultra\Projection\Coordination\ProjectionBucketActivityRepositoryInterface;

final class InMemoryProjectionBucketActivityRepository implements ProjectionBucketActivityRepositoryInterface
{
    /** @var array<string, array<int, int>> */
    private array $rows = [];

    public function bump(string $projectionId, int $bucket): void
    {
        $this->rows[$projectionId][$bucket] = ($this->rows[$projectionId][$bucket] ?? 0) + 1;
    }

    public function affectedBuckets(int $limit = 256): array
    {
        $affected = [];

        foreach (array_keys($this->rows) as $projectionId) {
            array_push($affected, ...$this->pendingBuckets($projectionId, $limit));
        }

        usort(
            $affected,
            static fn (ProjectionBucketActivity $a, ProjectionBucketActivity $b): int => ($a->projectionId <=> $b->projectionId)
                ?: ($b->counter <=> $a->counter)
                ?: ($a->bucket <=> $b->bucket),
        );

        return array_slice($affected, 0, $limit);
    }

    public function pendingBuckets(string $projectionId, int $limit = 32): array
    {
        $rows = $this->rows[$projectionId] ?? [];
        ksort($rows);

        $pending = [];
        foreach ($rows as $bucket => $counter) {
            $activity = new ProjectionBucketActivity(
                $projectionId,
                (int) $bucket,
                $counter,
            );
            if ($activity->isPending()) {
                $pending[] = $activity;
            }
        }

        usort(
            $pending,
            static fn (ProjectionBucketActivity $a, ProjectionBucketActivity $b): int => ($b->counter <=> $a->counter) ?: ($a->bucket <=> $b->bucket),
        );

        return array_slice($pending, 0, $limit);
    }

    public function pendingBucketsForSegment(string $projectionId, int $bucketFrom, int $bucketTo): array
    {
        $pending = [];

        foreach ($this->rows[$projectionId] ?? [] as $bucket => $counter) {
            if ($bucket < $bucketFrom || $bucket > $bucketTo || $counter <= 0) {
                continue;
            }

            $pending[] = new ProjectionBucketActivity($projectionId, (int) $bucket, $counter);
        }

        usort(
            $pending,
            static fn (ProjectionBucketActivity $a, ProjectionBucketActivity $b): int => ($b->counter <=> $a->counter) ?: ($a->bucket <=> $b->bucket),
        );

        return $pending;
    }

    public function decrementSeenBuckets(string $projectionId, array $seenCounters): int
    {
        $decremented = 0;

        foreach ($seenCounters as $bucket => $seenCounter) {
            $current = $this->rows[$projectionId][(int) $bucket] ?? 0;
            if ($current <= 0 || $seenCounter <= 0) {
                continue;
            }

            $next = max(0, $current - $seenCounter);
            $this->rows[$projectionId][(int) $bucket] = $next;
            ++$decremented;
        }

        return $decremented;
    }
}
