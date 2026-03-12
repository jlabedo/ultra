<?php

declare(strict_types=1);

namespace Ultra\DecisionEngine\Engine;

use Ultra\DecisionEngine\Attribute\DomainIdentifier;
use Ultra\DecisionEngine\Attribute\Event;
use Ultra\EventStore\AppendableEvent;
use Ultra\Mapper\UltraNormalizer;

final class EventRouter
{
    public function __construct(private readonly UltraNormalizer $normalizer)
    {
    }

    public function toAppendableEvent(object $event): AppendableEvent
    {
        $ref = new \ReflectionClass($event);
        $eventType = $this->getEventType($ref);
        $domainIds = $this->getDomainIds($ref, $event);
        $payload = $this->getPayload($event);

        return new AppendableEvent(
            id: $this->generateId(),
            eventType: $eventType,
            payload: $payload,
            domainIds: $domainIds,
        );
    }

    /**
     * @param list<object> $events
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

    private function getEventType(\ReflectionClass $ref): string
    {
        foreach ($ref->getAttributes(Event::class) as $attr) {
            return $attr->newInstance()->name;
        }

        throw new \LogicException(sprintf('Event class %s has no #[Event] attribute.', $ref->getName()));
    }

    /**
     * @return array<string, string>
     */
    private function getDomainIds(\ReflectionClass $ref, object $event): array
    {
        $domainIds = [];
        foreach ($ref->getProperties() as $property) {
            foreach ($property->getAttributes(DomainIdentifier::class) as $attr) {
                $name = $attr->newInstance()->name;
                if ($property->isPublic()) {
                    $value = $property->getValue($event);
                } else {
                    $getter = static function (string $prop): mixed {
                        return $this->$prop;
                    };
                    $value = $getter->call($event, $property->getName());
                }
                if (is_string($value)) {
                    $domainIds[$name] = $value;
                }
            }
        }

        if ($domainIds === []) {
            throw new \LogicException(sprintf('Event class %s has no properties with #[DomainIdentifier].', $ref->getName()));
        }

        return $domainIds;
    }

    /**
     * @return array<string, mixed>
     */
    private function getPayload(object $event): array
    {
        $payload = $this->normalizer->toScalar($event);
        if (!is_array($payload)) {
            throw new \LogicException(sprintf('Event %s normalized payload must be an array, got %s.', $event::class, get_debug_type($payload)));
        }

        return $payload;
    }

    private function generateId(): string
    {
        return bin2hex(random_bytes(8));
    }
}
