<?php

declare(strict_types=1);

namespace Ultra\Projection;

use Ultra\EventStore\Checkpoint;
use Ultra\EventStore\EventStore;

final readonly class ProjectionTopology
{
    public function __construct(
        private EventStore $eventStore,
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
}

