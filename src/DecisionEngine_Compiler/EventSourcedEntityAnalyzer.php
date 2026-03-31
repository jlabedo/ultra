<?php

declare(strict_types=1);

namespace Ultra\DecisionEngine_Compiler;

use Ultra\DecisionEngine\Attribute\EventSourcedEntity;
use Ultra\DecisionEngine\Attribute\EventSourcingHandler;
use Ultra\DecisionEngine\Attribute\MatchTag;
use Ultra\DecisionEngine\Exception\AmbiguousTagError;
use Ultra\DecisionEngine\Exception\DuplicateEventSourcingHandlerError;
use Ultra\DecisionEngine\Exception\InvalidMatchTagError;
use Ultra\DecisionEngine\Exception\MissingEventSourcingHandlerError;
use Ultra\DecisionEngine\Exception\MissingTypeHintError;
use Ultra\DecisionEngine\Exception\NoTagOverlapError;
use Ultra\DecisionEngine\Exception\UnnecessaryMatchTagError;
use Ultra\Events\Attribute\DomainIdentifier;
use Ultra\Events\Attribute\Event;

final class EventSourcedEntityAnalyzer
{
    public function analyze(string $entityClass): EventSourcedEntityDefinition
    {
        $entityRef = new \ReflectionClass($entityClass);
        $attributes = $entityRef->getAttributes(EventSourcedEntity::class);
        if ([] === $attributes) {
            throw new \LogicException(sprintf(
                'Class %s is not marked with #[EventSourcedEntity].',
                $entityClass,
            ));
        }

        /** @var EventSourcedEntity $entityAttribute */
        $entityAttribute = $attributes[0]->newInstance();
        $entityTags = $this->normalizeTags($entityAttribute->tags);

        /** @var array<string, array{tags: list<string>, eventNames: array<string, true>}> $criteriaGroups */
        $criteriaGroups = [];
        /** @var array<string, array{eventClass: string, eventName: string, method: string}> $dispatchEntries */
        $dispatchEntries = [];

        foreach ($entityRef->getMethods() as $method) {
            $matchTagAttributes = $method->getAttributes(MatchTag::class);
            $handlerAttributes = $method->getAttributes(EventSourcingHandler::class);

            if ([] !== $matchTagAttributes && [] === $handlerAttributes) {
                throw new MissingEventSourcingHandlerError($entityClass, $method->getName());
            }

            if ([] === $handlerAttributes) {
                continue;
            }

            if (! $method->isPublic()) {
                throw new \LogicException(sprintf(
                    'Handler %s::%s must be public.',
                    $entityClass,
                    $method->getName(),
                ));
            }

            $parameters = $method->getParameters();
            if (1 !== count($parameters)) {
                throw new MissingTypeHintError($entityClass, $method->getName());
            }

            $parameterType = $parameters[0]->getType();
            if (! $parameterType instanceof \ReflectionNamedType || $parameterType->isBuiltin()) {
                throw new MissingTypeHintError($entityClass, $method->getName());
            }

            $eventClass = $parameterType->getName();
            $eventTags = $this->eventTags($eventClass);
            $eventName = $this->eventName($eventClass);
            $intersection = $this->intersectTags($entityTags, $eventTags);

            if ([] === $intersection) {
                throw new NoTagOverlapError($entityClass, $method->getName(), $eventClass);
            }

            $resolvedGroups = $this->resolveMatchGroups(
                class: $entityClass,
                method: $method->getName(),
                eventClass: $eventClass,
                intersection: $intersection,
                matchTagAttributes: $matchTagAttributes,
                entityTags: $entityTags,
            );

            foreach ($resolvedGroups as $groupTags) {
                $groupKey = implode("\0", $groupTags);
                if (! isset($criteriaGroups[$groupKey])) {
                    $criteriaGroups[$groupKey] = [
                        'tags' => $groupTags,
                        'eventNames' => [],
                    ];
                }
                $criteriaGroups[$groupKey]['eventNames'][$eventName] = true;
            }

            if (isset($dispatchEntries[$eventName])) {
                throw new DuplicateEventSourcingHandlerError(
                    $entityClass,
                    $eventClass,
                    $dispatchEntries[$eventName]['method'],
                    $method->getName(),
                );
            }

            $dispatchEntries[$eventName] = [
                'eventClass' => $eventClass,
                'eventName' => $eventName,
                'method' => $method->getName(),
            ];
        }

        $compiledGroups = [];
        foreach ($criteriaGroups as $group) {
            $eventNames = array_keys($group['eventNames']);
            sort($eventNames);
            $compiledGroups[] = new EventSourcedCriteriaGroup($group['tags'], $eventNames);
        }

        usort($compiledGroups, static function (EventSourcedCriteriaGroup $left, EventSourcedCriteriaGroup $right): int {
            $tagComparison = implode('|', $left->tags) <=> implode('|', $right->tags);
            if (0 !== $tagComparison) {
                return $tagComparison;
            }

            return implode('|', $left->eventNames) <=> implode('|', $right->eventNames);
        });

        $compiledDispatch = [];
        foreach ($dispatchEntries as $entry) {
            $compiledDispatch[] = new EventSourcedDispatchEntry(
                eventClass: $entry['eventClass'],
                eventName: $entry['eventName'],
                method: $entry['method'],
            );
        }

        usort($compiledDispatch, static function (EventSourcedDispatchEntry $left, EventSourcedDispatchEntry $right): int {
            return $left->eventName <=> $right->eventName;
        });

        return new EventSourcedEntityDefinition(
            entityClass: $entityClass,
            tags: $entityTags,
            criteriaGroups: $compiledGroups,
            dispatchEntries: $compiledDispatch,
        );
    }

    /**
     * @param list<string> $tags
     *
     * @return list<string>
     */
    private function normalizeTags(array $tags): array
    {
        $normalized = [];
        foreach ($tags as $tag) {
            if (! is_string($tag) || '' === $tag) {
                throw new \LogicException('Event-sourced entity tags must be non-empty strings.');
            }
            $normalized[$tag] = true;
        }

        return array_keys($normalized);
    }

    /**
     * @return list<string>
     */
    private function eventTags(string $eventClass): array
    {
        if (! class_exists($eventClass)) {
            throw new \LogicException(sprintf('Event class %s does not exist.', $eventClass));
        }

        $eventRef = new \ReflectionClass($eventClass);
        $tags = [];

        foreach ($eventRef->getProperties() as $property) {
            foreach ($property->getAttributes(DomainIdentifier::class) as $attribute) {
                $tags[$attribute->newInstance()->name] = true;
            }
        }

        return array_keys($tags);
    }

    private function eventName(string $eventClass): string
    {
        $eventRef = new \ReflectionClass($eventClass);
        $attributes = $eventRef->getAttributes(Event::class);
        if ([] === $attributes) {
            throw new \LogicException(sprintf(
                'Event class %s has no #[Event] attribute.',
                $eventClass,
            ));
        }

        /** @var Event $event */
        $event = $attributes[0]->newInstance();

        return $event->name;
    }

    /**
     * @param list<string>                                $intersection
     * @param list<\ReflectionAttribute<MatchTag>>        $matchTagAttributes
     * @param list<string>                                $entityTags
     *
     * @return list<list<string>>
     */
    private function resolveMatchGroups(
        string $class,
        string $method,
        string $eventClass,
        array $intersection,
        array $matchTagAttributes,
        array $entityTags,
    ): array {
        if (1 === count($intersection)) {
            if ([] !== $matchTagAttributes) {
                throw new UnnecessaryMatchTagError($class, $method, $eventClass, $intersection[0]);
            }

            return [$intersection];
        }

        if ([] === $matchTagAttributes) {
            throw new AmbiguousTagError($class, $method, $eventClass, $intersection);
        }

        $resolved = [];
        foreach ($matchTagAttributes as $attribute) {
            $group = $this->normalizeTags($attribute->newInstance()->tags);
            if ([] === $group) {
                throw new \LogicException(sprintf(
                    'Handler %s::%s has an empty #[MatchTag].',
                    $class,
                    $method,
                ));
            }

            foreach ($group as $tag) {
                if (! in_array($tag, $intersection, true)) {
                    throw new InvalidMatchTagError($class, $method, $tag, $intersection);
                }
            }

            $resolved[] = $this->sortTagsByEntityOrder($group, $entityTags);
        }

        return $resolved;
    }

    /**
     * @param list<string> $entityTags
     * @param list<string> $eventTags
     *
     * @return list<string>
     */
    private function intersectTags(array $entityTags, array $eventTags): array
    {
        $intersection = [];
        foreach ($entityTags as $tag) {
            if (in_array($tag, $eventTags, true)) {
                $intersection[] = $tag;
            }
        }

        return $intersection;
    }

    /**
     * @param list<string> $tags
     * @param list<string> $entityTags
     *
     * @return list<string>
     */
    private function sortTagsByEntityOrder(array $tags, array $entityTags): array
    {
        $positions = array_flip($entityTags);
        usort($tags, static fn (string $left, string $right): int => $positions[$left] <=> $positions[$right]);

        return array_values($tags);
    }
}
