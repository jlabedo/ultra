<?php

declare(strict_types=1);

namespace Ultra\Projection;

use Ultra\EventStore\Checkpoint;
use Ultra\EventStore\EventStore;

final readonly class ProjectionTopology
{
    public function __construct(
        private EventStore $eventStore,
        private ProjectionSegmentStoreInterface $segmentStore,
        private ProjectionTopologyStoreInterface $topologyStore,
        private ProjectionTransactionManagerInterface $transactionManager = new NoOpProjectionTransactionManager(),
    ) {}

    public function split(string $projectionId, int $bucketFrom): void
    {
        $this->topologyStore->splitSegment($projectionId, $bucketFrom);
    }

    public function merge(ProjectionRuntime $runtime, int $bucketFrom, int $bucketTo, string $ownerId, int $limit = 100): void
    {
        $projectionId = $runtime->projectionId();
        $segments = $this->topologyStore->takeSegmentsForRange($projectionId, $bucketFrom, $bucketTo, $ownerId);
        if ([] === $segments) {
            throw new \LogicException(sprintf(
                'Cannot merge [%d-%d] for projection "%s": no matching segments found.',
                $bucketFrom,
                $bucketTo,
                $projectionId,
            ));
        }

        $targetCheckpoint = $this->mergeCheckpoint(...array_map(static fn (ProjectionSegment $segment): Checkpoint => $segment->checkpoint, $segments));

        foreach ($segments as $segment) {
            $checkpoint = $segment->checkpoint;
            $query = $runtime->definition()->streamQueryForSegment($segment->bucketFrom, $segment->bucketTo);

            while ($checkpoint->serialize() !== $targetCheckpoint->serialize()) {
                $result = $this->eventStore->load($query, $checkpoint, $targetCheckpoint, $limit);
                $this->transactionManager->transactional(function () use ($runtime, $result): void {
                    foreach ($result->events as $event) {
                        $runtime->apply($event);
                    }

                    $runtime->flush();
                });

                if ($result->checkpoint->serialize() === $checkpoint->serialize()) {
                    throw new \LogicException(sprintf(
                        'Projection merge for "%s" [%d-%d] could not advance segment [%d-%d] to the target checkpoint.',
                        $projectionId,
                        $bucketFrom,
                        $bucketTo,
                        $segment->bucketFrom,
                        $segment->bucketTo,
                    ));
                }

                $checkpoint = $result->checkpoint;
            }
        }

        $this->topologyStore->replaceSegmentsWithMergedSegment($projectionId, $bucketFrom, $bucketTo, $targetCheckpoint);
    }

    /**
     * @return list<ProjectionSegment>
     */
    public function reshapeToCanonicalSegments(ProjectionRuntime $runtime, int $targetSegments, string $ownerId, int $limit = 100): array
    {
        $this->assertValidCanonicalSegmentCount($targetSegments);
        if ($limit <= 0) {
            throw new \InvalidArgumentException('Projection topology limit must be positive.');
        }

        $projectionId = $runtime->projectionId();
        $segments = $this->segmentStore->listSegments($projectionId);
        $this->assertNoActiveClaims($projectionId, $segments);
        $this->assertCanonicalTopology($projectionId, $segments);

        while (count($segments) < $targetSegments) {
            foreach ($segments as $segment) {
                $this->split($projectionId, $segment->bucketFrom);
            }

            $segments = $this->segmentStore->listSegments($projectionId);
            $this->assertCanonicalTopology($projectionId, $segments);
        }

        while (count($segments) > $targetSegments) {
            $targetRangeSize = intdiv(256, intdiv(count($segments), 2));
            for ($bucketFrom = 0; $bucketFrom < 256; $bucketFrom += $targetRangeSize) {
                $this->merge(
                    $runtime,
                    $bucketFrom,
                    $bucketFrom + $targetRangeSize - 1,
                    sprintf('%s:topology:%s:%d', $ownerId, $projectionId, $bucketFrom),
                    $limit,
                );
            }

            $segments = $this->segmentStore->listSegments($projectionId);
            $this->assertCanonicalTopology($projectionId, $segments);
        }

        $this->assertNoActiveClaims($projectionId, $segments);
        if (count($segments) !== $targetSegments) {
            throw new \LogicException(sprintf('Projection "%s" topology could not be reshaped to %d segments.', $projectionId, $targetSegments));
        }

        return $segments;
    }

    private function mergeCheckpoint(Checkpoint ...$checkpoints): Checkpoint
    {
        $highest = $checkpoints[0] ?? Checkpoint::beginning();

        foreach ($checkpoints as $checkpoint) {
            $comparison = $highest->gapFloor->compareTo($checkpoint->gapFloor);
            if ($comparison < 0) {
                $highest = $checkpoint;

                continue;
            }

            if (0 === $comparison) {
                $highest = new Checkpoint($highest->gapFloor, [...$highest->gapSeqNos, ...$checkpoint->gapSeqNos]);
            }
        }

        return $highest;
    }

    private function assertValidCanonicalSegmentCount(int $segments): void
    {
        if ($segments < 1 || $segments > 256 || 0 !== (256 % $segments)) {
            throw new \InvalidArgumentException('Canonical projection segment count must be one of: 1, 2, 4, 8, 16, 32, 64, 128, 256.');
        }
    }

    /**
     * @param list<ProjectionSegment> $segments
     */
    private function assertNoActiveClaims(string $projectionId, array $segments): void
    {
        foreach ($segments as $segment) {
            if (null !== $segment->workerId) {
                throw new \LogicException(sprintf(
                    'Projection "%s" cannot be reshaped while segment [%d-%d] is claimed by "%s".',
                    $projectionId,
                    $segment->bucketFrom,
                    $segment->bucketTo,
                    $segment->workerId,
                ));
            }
        }
    }

    /**
     * @param list<ProjectionSegment> $segments
     */
    private function assertCanonicalTopology(string $projectionId, array $segments): void
    {
        $count = count($segments);
        if (0 === $count || 0 !== (256 % $count)) {
            throw new \LogicException(sprintf('Projection "%s" topology is not canonical: segment count must divide 256.', $projectionId));
        }

        $rangeSize = intdiv(256, $count);
        foreach ($segments as $index => $segment) {
            $expectedFrom = $index * $rangeSize;
            $expectedTo = $expectedFrom + $rangeSize - 1;
            if ($segment->bucketFrom !== $expectedFrom || $segment->bucketTo !== $expectedTo) {
                throw new \LogicException(sprintf(
                    'Projection "%s" topology is not canonical: expected segment [%d-%d], got [%d-%d].',
                    $projectionId,
                    $expectedFrom,
                    $expectedTo,
                    $segment->bucketFrom,
                    $segment->bucketTo,
                ));
            }
        }
    }
}
