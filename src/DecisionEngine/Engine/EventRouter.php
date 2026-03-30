<?php

declare(strict_types=1);

namespace Ultra\DecisionEngine\Engine;

use Ultra\DecisionEngine\Attribute\DomainIdentifier;
use Ultra\EventBus\EventRegistryInterface;
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
        $ref = new \ReflectionClass($event);
        $eventType = $this->getEventType($ref);
        $domainIds = $this->getDomainIds($ref, $event);
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

    private function getEventType(\ReflectionClass $ref): string
    {
        $eventType = $this->eventRegistry->getName($ref->getName());
        if (null !== $eventType) {
            return $eventType;
        }

        throw new \LogicException(sprintf('Event class %s has no #[Event] attribute.', $ref->getName()));
    }

    /**
     * @return array<string, string>
     */
    private function getDomainIds(\ReflectionClass $ref, object $event): array
    {
        $domainIds = [];

        $constructor = $ref->getConstructor();
        if (null !== $constructor) {
            foreach ($constructor->getParameters() as $parameter) {
                foreach ($parameter->getAttributes(DomainIdentifier::class) as $attr) {
                    $name = $attr->newInstance()->name;
                    $value = $this->readPropertyValue($ref, $event, $parameter->getName());
                    if (is_string($value)) {
                        $domainIds[$name] = $value;
                    }
                }
            }
        }

        foreach ($ref->getProperties() as $property) {
            foreach ($property->getAttributes(DomainIdentifier::class) as $attr) {
                $name = $attr->newInstance()->name;
                $value = $this->readPropertyValue($ref, $event, $property->getName());
                if (is_string($value)) {
                    $domainIds[$name] = $value;
                }
            }
        }

        if ([] === $domainIds) {
            throw new \LogicException(sprintf('Event class %s has no properties with #[DomainIdentifier].', $ref->getName()));
        }

        return $domainIds;
    }

    private function readPropertyValue(\ReflectionClass $ref, object $event, string $property): mixed
    {
        if (! $ref->hasProperty($property)) {
            throw new \LogicException(sprintf(
                'Event class %s declares #[DomainIdentifier] on "%s" but has no matching property.',
                $ref->getName(),
                $property,
            ));
        }

        $refProperty = $ref->getProperty($property);
        if ($refProperty->isPublic()) {
            return $refProperty->getValue($event);
        }

        $getter = static function (string $prop): mixed {
            return $this->{$prop};
        };

        return $getter->call($event, $property);
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
