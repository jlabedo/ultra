<?php

declare(strict_types=1);

namespace Ultra\DecisionEngine\Engine;

use Ultra\Events\EventRegistryInterface;
use Ultra\EventStore\AppendableEvent;
use Ultra\Mapper\UltraNormalizer;

final class EventRouter
{
    public function __construct(
        private readonly UltraNormalizer $normalizer,
        private readonly EventRegistryInterface $eventRegistry,
    ) {}

    public function toAppendableEvent(object $event): AppendableEvent
    {
        $eventType = $this->getEventType($event);
        $domainIds = $this->eventRegistry->getDomainIdentifiers($event);
        if ([] === $domainIds) {
            throw new \LogicException(sprintf('Event class %s has no properties with #[DomainIdentifier].', $event::class));
        }
        $payload = $this->getPayload($event);

        return new AppendableEvent(
            eventType: $eventType,
            payload: $payload,
            domainIds: $domainIds,
        );
    }

    /**
     * @param list<object> $events
     *
     * @return list<AppendableEvent>
     */
    public function toAppendableEvents(array $events): array
    {
        $result = [];
        foreach ($events as $event) {
            $result[] = $this->toAppendableEvent($event);
        }

        return $result;
    }

    private function getEventType(object $event): string
    {
        $eventType = $this->eventRegistry->getName($event::class);
        if (null !== $eventType) {
            return $eventType;
        }

        throw new \LogicException(sprintf('Event class %s has no #[Event] attribute.', $event::class));
    }

    /**
     * @return array<string, mixed>
     */
    private function getPayload(object $event): array
    {
        $payload = $this->normalizer->toScalar($event);
        if (! is_array($payload)) {
            throw new \LogicException(sprintf('Event %s normalized payload must be an array, got %s.', $event::class, get_debug_type($payload)));
        }

        return $payload;
    }
}
