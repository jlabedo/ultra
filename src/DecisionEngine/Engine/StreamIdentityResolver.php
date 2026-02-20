<?php

declare(strict_types=1);

namespace Ultra\DecisionEngine\Engine;

use Ultra\EventStore\DomainIdentifier;
use Ultra\EventStore\StreamQuery;

final class StreamIdentityResolver
{
    /**
     * Returns a single StreamQuery that matches events for any of the command's streams (OR semantics).
     * The store can load once and return one StreamVersion to use when appending.
     */
    public function resolveStreamQuery(object $command, CommandMetadata $metadata): StreamQuery
    {
        $branches = [];
        foreach ($metadata->stateParameters as $stateParam) {
            $value = $this->getCommandPropertyValue($command, $stateParam->commandProperty);
            $branches[] = StreamQuery::forStream(new DomainIdentifier($stateParam->domainIdentifierName, $value));
        }

        return StreamQuery::anyOf(...$branches);
    }

    private function getCommandPropertyValue(object $command, string $property): string
    {
        $ref = new \ReflectionClass($command);
        if (!$ref->hasProperty($property)) {
            throw new \LogicException(sprintf('Command %s has no property "%s".', $ref->getName(), $property));
        }
        $prop = $ref->getProperty($property);
        $prop->setAccessible(true);
        $value = $prop->getValue($command);
        if (!is_string($value)) {
            throw new \LogicException(sprintf('Command property "%s" must be string, got %s.', $property, get_debug_type($value)));
        }

        return $value;
    }
}
