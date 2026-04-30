<?php

declare(strict_types=1);

namespace Ultra\Projection\InMemory;

use Ultra\EventStore\Checkpoint;
use Ultra\Projection\ProjectionSegment;
use Ultra\Projection\ProjectionSegmentRepositoryInterface;
use Ultra\Projection\ProjectionWorkAssignment;
use Ultra\Time\ClockInterface;
use Ultra\Time\Duration;
use Ultra\Time\SystemClock;

final class InMemoryProjectionSegmentRepository implements ProjectionSegmentRepositoryInterface
{
    /** @var array<string, array<int, array{bucketFrom: int, bucketTo: int, checkpoint: Checkpoint, workerId: null|string, leaseExpiresAt: null|int, assignmentId: null|string}>> */
    private array $segments = [];

    public function __construct(private ClockInterface $clock = new SystemClock())
    {
    }

    public function ensureInitialized(string $projectionId): bool
    {
        if (isset($this->segments[$projectionId]) && [] !== $this->segments[$projectionId]) {
            return false;
        }

        $this->segments[$projectionId] = [
            0 => [
                'bucketFrom' => 0,
                'bucketTo' => 255,
                'checkpoint' => Checkpoint::beginning(),
                'workerId' => null,
                'leaseExpiresAt' => null,
                'assignmentId' => null,
            ],
        ];

        return true;
    }

    public function assignSegment(string $projectionId, int $bucketFrom, string $workerId, ?Duration $leaseDuration = null): ?ProjectionWorkAssignment
    {
        $leaseDuration ??= Duration::seconds(60);
        $row = $this->segments[$projectionId][$bucketFrom] ?? null;
        if (null === $row || null !== $row['workerId']) {
            return null;
        }

        $assignmentId = bin2hex(random_bytes(16));
        $row['workerId'] = $workerId;
        $row['leaseExpiresAt'] = $this->clock->now()->add($leaseDuration);
        $row['assignmentId'] = $assignmentId;
        $this->segments[$projectionId][$bucketFrom] = $row;

        return new ProjectionWorkAssignment($assignmentId, $projectionId, $bucketFrom, $workerId);
    }

    public function claimAssignedSegment(ProjectionWorkAssignment $assignment, ?Duration $leaseDuration = null): ?ProjectionSegment
    {
        $leaseDuration ??= Duration::seconds(60);
        $row = $this->segments[$assignment->projectionId][$assignment->bucketFrom] ?? null;
        if (
            null === $row
            || $row['workerId'] !== $assignment->workerId
            || $row['assignmentId'] !== $assignment->assignmentId
            || (null !== $row['leaseExpiresAt'] && $row['leaseExpiresAt'] < $this->clock->now())
        ) {
            return null;
        }

        $row['leaseExpiresAt'] = $this->clock->now()->add($leaseDuration);
        $this->segments[$assignment->projectionId][$assignment->bucketFrom] = $row;

        return $this->toSegment($assignment->projectionId, $row);
    }

    public function completeAssignedBatch(
        ProjectionSegment $segment,
        Checkpoint $checkpoint,
        string $workerId,
        bool $caughtUp,
        ?Duration $leaseDuration = null,
    ): void {
        $leaseDuration ??= Duration::seconds(60);
        $row = $this->segments[$segment->projectionId][$segment->bucketFrom] ?? null;
        if (null === $row || $row['workerId'] !== $workerId) {
            return;
        }

        $row['checkpoint'] = $checkpoint;
        if ($caughtUp) {
            $row['workerId'] = null;
            $row['leaseExpiresAt'] = null;
            $row['assignmentId'] = null;
        } else {
            $row['leaseExpiresAt'] = $this->clock->now()->add($leaseDuration);
        }

        $this->segments[$segment->projectionId][$segment->bucketFrom] = $row;
    }

    public function abandonAssignedSegment(ProjectionSegment $segment, string $workerId): void
    {
        $row = $this->segments[$segment->projectionId][$segment->bucketFrom] ?? null;
        if (null === $row || $row['workerId'] !== $workerId) {
            return;
        }

        $row['workerId'] = null;
        $row['leaseExpiresAt'] = null;
        $row['assignmentId'] = null;
        $this->segments[$segment->projectionId][$segment->bucketFrom] = $row;
    }

    public function recoverExpiredAssignments(string $projectionId): int
    {
        $recovered = 0;

        foreach (array_keys($this->segments[$projectionId] ?? []) as $bucketFrom) {
            $row = $this->segments[$projectionId][$bucketFrom];
            if (null === $row['workerId']) {
                continue;
            }

            if (null === $row['leaseExpiresAt'] || $row['leaseExpiresAt'] >= $this->clock->now()) {
                continue;
            }

            $row['workerId'] = null;
            $row['leaseExpiresAt'] = null;
            $row['assignmentId'] = null;
            $this->segments[$projectionId][$bucketFrom] = $row;
            ++$recovered;
        }

        return $recovered;
    }

    public function splitSegment(string $projectionId, int $bucketFrom): void
    {
        $row = $this->segments[$projectionId][$bucketFrom] ?? null;
        if (null === $row) {
            throw new \InvalidArgumentException(sprintf(
                'Segment [%d] not found for projection "%s".',
                $bucketFrom,
                $projectionId,
            ));
        }

        $this->finalizeSplit($this->toSegment($projectionId, $row), $row['checkpoint']);
    }

    public function listSegments(string $projectionId): array
    {
        $rows = $this->segments[$projectionId] ?? [];
        ksort($rows);

        return array_map(static fn (array $row): array => [
            'bucketFrom' => $row['bucketFrom'],
            'bucketTo' => $row['bucketTo'],
            'workerId' => $row['workerId'],
        ], array_values($rows));
    }

    private function finalizeSplit(ProjectionSegment $segment, Checkpoint $checkpoint): void
    {
        $rangeSize = $segment->bucketTo - $segment->bucketFrom + 1;
        if ($rangeSize < 2) {
            throw new \LogicException(sprintf(
                'Segment [%d-%d] is at minimum granularity and cannot be split.',
                $segment->bucketFrom,
                $segment->bucketTo,
            ));
        }

        $mid = $segment->bucketFrom + intdiv($rangeSize, 2);
        unset($this->segments[$segment->projectionId][$segment->bucketFrom]);

        $this->segments[$segment->projectionId][$segment->bucketFrom] = [
            'bucketFrom' => $segment->bucketFrom,
            'bucketTo' => $mid - 1,
            'checkpoint' => $checkpoint,
            'workerId' => null,
            'leaseExpiresAt' => null,
            'assignmentId' => null,
        ];
        $this->segments[$segment->projectionId][$mid] = [
            'bucketFrom' => $mid,
            'bucketTo' => $segment->bucketTo,
            'checkpoint' => $checkpoint,
            'workerId' => null,
            'leaseExpiresAt' => null,
            'assignmentId' => null,
        ];
        ksort($this->segments[$segment->projectionId]);
    }

    private function segmentsForRange(string $projectionId, int $bucketFrom, int $bucketTo): array
    {
        return array_values(array_map(
            fn (array $row): ProjectionSegment => $this->toSegment($projectionId, $row),
            array_filter(
                $this->segments[$projectionId] ?? [],
                static fn (array $row): bool => $row['bucketFrom'] >= $bucketFrom && $row['bucketTo'] <= $bucketTo,
            ),
        ));
    }

    public function takeSegmentsForRange(
        string $projectionId,
        int $bucketFrom,
        int $bucketTo,
        string $ownerId,
        ?Duration $leaseDuration = null,
    ): array {
        $leaseDuration ??= Duration::seconds(60);
        $segments = $this->segmentsForRange($projectionId, $bucketFrom, $bucketTo);
        $this->assertSegmentsExactlyCoverRange($projectionId, $segments, $bucketFrom, $bucketTo);

        foreach ($segments as $segment) {
            $row = $this->segments[$projectionId][$segment->bucketFrom];
            $row['workerId'] = $ownerId;
            $row['leaseExpiresAt'] = $this->clock->now()->add($leaseDuration);
            $row['assignmentId'] = null;
            $this->segments[$projectionId][$segment->bucketFrom] = $row;
        }

        return $segments;
    }

    public function replaceSegmentsWithMergedSegment(string $projectionId, int $bucketFrom, int $bucketTo, Checkpoint $checkpoint): void
    {
        $segments = $this->segmentsForRange($projectionId, $bucketFrom, $bucketTo);
        if ([] === $segments) {
            throw new \LogicException(sprintf(
                'Cannot merge [%d-%d] for projection "%s": no matching segments found.',
                $bucketFrom,
                $bucketTo,
                $projectionId,
            ));
        }

        $this->assertSegmentsExactlyCoverRange($projectionId, $segments, $bucketFrom, $bucketTo);

        foreach ($segments as $segment) {
            unset($this->segments[$projectionId][$segment->bucketFrom]);
        }

        $this->segments[$projectionId][$bucketFrom] = [
            'bucketFrom' => $bucketFrom,
            'bucketTo' => $bucketTo,
            'checkpoint' => $checkpoint,
            'workerId' => null,
            'leaseExpiresAt' => null,
            'assignmentId' => null,
        ];
        ksort($this->segments[$projectionId]);
    }

    public function availableSegments(string $projectionId): array
    {
        return array_values(array_filter(
            $this->listSegments($projectionId),
            static fn (array $segment): bool => null === $segment['workerId'],
        ));
    }

    /**
     * @param list<ProjectionSegment> $segments
     */
    private function assertSegmentsExactlyCoverRange(string $projectionId, array $segments, int $bucketFrom, int $bucketTo): void
    {
        usort($segments, static fn (ProjectionSegment $left, ProjectionSegment $right): int => $left->bucketFrom <=> $right->bucketFrom);

        $next = $bucketFrom;
        foreach ($segments as $segment) {
            if ($segment->bucketFrom !== $next) {
                throw new \LogicException(sprintf('Cannot merge [%d-%d] for projection "%s": segments do not cover the range.', $bucketFrom, $bucketTo, $projectionId));
            }

            $next = $segment->bucketTo + 1;
        }

        if ($next !== $bucketTo + 1) {
            throw new \LogicException(sprintf('Cannot merge [%d-%d] for projection "%s": segments do not cover the range.', $bucketFrom, $bucketTo, $projectionId));
        }
    }

    /**
     * @param array{bucketFrom: int, bucketTo: int, checkpoint: Checkpoint, workerId: null|string, leaseExpiresAt: null|int, assignmentId: null|string} $row
     */
    private function toSegment(string $projectionId, array $row): ProjectionSegment
    {
        return new ProjectionSegment(
            $projectionId,
            $row['bucketFrom'],
            $row['bucketTo'],
            $row['checkpoint'],
            false,
        );
    }
}
