<?php

declare(strict_types=1);

namespace Ultra\Projection;

use Ultra\EventBus\EventRegistryInterface;
use Ultra\EventStore\StoredEvent;
use Ultra\Mapper\UltraNormalizer;

final readonly class ProjectionEnvelopeFactory
{
    public function __construct(
        private EventRegistryInterface $eventRegistry,
        private UltraNormalizer $normalizer,
    ) {}

    public function fromStoredEvent(StoredEvent $event): ProjectionEnvelope
    {
        $eventClass = $this->eventRegistry->getClass($event->eventType);
        if (null === $eventClass) {
            throw new \LogicException(sprintf(
                'No event class is registered for stored event type "%s".',
                $event->eventType,
            ));
        }

        return new ProjectionEnvelope(
            eventId: $event->eventId,
            eventType: $event->eventType,
            event: $this->normalizer->fromScalar($event->payload, $eventClass),
            domainIds: $event->domainIds,
            recordedAt: $event->recordedAt,
        );
    }
}
