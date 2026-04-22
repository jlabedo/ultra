<?php

declare(strict_types=1);

namespace Ultra\Projection\Coordination;

final class InMemoryProjectionBucketActivityRepository implements ProjectionBucketActivityRepositoryInterface
{
    /** @var array<string, array<int, array{counter: int, acknowledgedCounter: int}>> */
    private array $rows = [];

    public function bump(string $projectionId, int $bucket): void
    {
        $row = $this->rows[$projectionId][$bucket] ?? ['counter' => 0, 'acknowledgedCounter' => 0];
        ++$row['counter'];
        $this->rows[$projectionId][$bucket] = $row;
    }

    public function pendingBuckets(string $projectionId, int $limit = 32): array
    {
        $rows = $this->rows[$projectionId] ?? [];
        ksort($rows);

        $pending = [];
        foreach ($rows as $bucket => $row) {
            $activity = new ProjectionBucketActivity(
                $projectionId,
                (int) $bucket,
                $row['counter'],
                $row['acknowledgedCounter'],
            );
            if ($activity->isPending()) {
                $pending[] = $activity;
            }
        }

        usort(
            $pending,
            static fn(ProjectionBucketActivity $a, ProjectionBucketActivity $b): int =>
                ($b->counter <=> $a->counter) ?: ($a->bucket <=> $b->bucket),
        );

        return array_slice($pending, 0, $limit);
    }

    public function acknowledge(string $projectionId, int $bucket, int $counter): void
    {
        $row = $this->rows[$projectionId][$bucket] ?? ['counter' => 0, 'acknowledgedCounter' => 0];
        $row['acknowledgedCounter'] = max($row['acknowledgedCounter'], $counter);
        $this->rows[$projectionId][$bucket] = $row;
    }
}
