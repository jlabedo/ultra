<?php

declare(strict_types=1);

namespace Ultra\Projection;

use Ultra\EventStore\Event;

final readonly class ProjectionDescriptor
{
    /**
     * @param list<string> $handledEventTypes
     */
    public function __construct(
        public string $projectionId,
        public string $partitionDimension,
        public array $handledEventTypes,
    ) {}

    public static function fromPlan(ProjectionPlan $plan): self
    {
        return new self(
            $plan->projectionId(),
            $plan->partitionDimension(),
            $plan->handledEventTypes(),
        );
    }

    public function handles(Event $event): bool
    {
        return in_array($event->eventType, $this->handledEventTypes, true);
    }

    public function bucketFor(Event $event): ?int
    {
        $value = $event->domainIds[$this->partitionDimension] ?? null;
        if (null === $value) {
            return null;
        }

        return \Ultra\EventStore\BucketHasher::bucketFor($value);
    }
}
