<?php

declare(strict_types=1);

namespace Ultra\Projection;

use Ultra\EventStore\Event;
use Ultra\Events\Attribute\Event as EventAttribute;
use Ultra\Mapper\UltraNormalizer;
use Ultra\Projection\Attribute\ProjectionHandler;

final class ProjectionHandlerMap
{
    /** @param array<string, array{eventClass: string, method: string}> $entries */
    private function __construct(private readonly array $entries) {}

    public static function fromClass(string $class): self
    {
        if (!class_exists($class)) {
            throw new \InvalidArgumentException(sprintf('Class %s does not exist.', $class));
        }

        $reflection = new \ReflectionClass($class);
        $entries = [];

        foreach ($reflection->getMethods(\ReflectionMethod::IS_PUBLIC) as $method) {
            if ([] === $method->getAttributes(ProjectionHandler::class)) {
                continue;
            }

            $parameters = $method->getParameters();
            if (1 !== count($parameters)) {
                throw new \LogicException(sprintf(
                    '#[ProjectionHandler] method %s::%s must have exactly one parameter.',
                    $class,
                    $method->getName(),
                ));
            }

            $type = $parameters[0]->getType();
            if (!$type instanceof \ReflectionNamedType || $type->isBuiltin()) {
                throw new \LogicException(sprintf(
                    '#[ProjectionHandler] method %s::%s must type-hint a specific event class.',
                    $class,
                    $method->getName(),
                ));
            }

            $eventClass = $type->getName();
            $eventTypeName = self::resolveEventTypeName($eventClass, $class, $method->getName());

            if (isset($entries[$eventTypeName])) {
                throw new \LogicException(sprintf(
                    'Duplicate #[ProjectionHandler] for event type "%s" in %s.',
                    $eventTypeName,
                    $class,
                ));
            }

            $entries[$eventTypeName] = ['eventClass' => $eventClass, 'method' => $method->getName()];
        }

        return new self($entries);
    }

    public function apply(object $target, Event $event, UltraNormalizer $normalizer): void
    {
        $entry = $this->entries[$event->eventType] ?? null;
        if (null === $entry) {
            return;
        }

        $deserialized = $normalizer->fromScalar($event->payload, $entry['eventClass']);
        $method = $entry['method'];
        $target->$method($deserialized);
    }

    /** @return list<string> */
    public function eventTypeNames(): array
    {
        return array_keys($this->entries);
    }

    private static function resolveEventTypeName(string $eventClass, string $projectionClass, string $method): string
    {
        if (!class_exists($eventClass)) {
            throw new \LogicException(sprintf(
                'Event class %s (referenced in %s::%s) does not exist.',
                $eventClass, $projectionClass, $method,
            ));
        }

        $attributes = (new \ReflectionClass($eventClass))->getAttributes(EventAttribute::class);
        if ([] === $attributes) {
            throw new \LogicException(sprintf(
                'Event class %s has no #[Event] attribute (referenced in %s::%s).',
                $eventClass, $projectionClass, $method,
            ));
        }

        return $attributes[0]->newInstance()->name;
    }
}
