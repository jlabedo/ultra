<?php

declare(strict_types=1);

namespace Ultra\DecisionEngine\Engine;

use Ultra\EventStore\StreamQuery;

final class StreamIdentityResolver
{
    /**
     * Returns one StreamQuery per state parameter, in the same order as CommandMetadata::stateParameters.
     * Each query has a single domain identifier (dimension => value) from the command.
     *
     * @return list<StreamQuery>
     */
    public function resolveStreamQueries(object $command, CommandMetadata $metadata): array
    {
        $queries = [];
        foreach ($metadata->stateParameters as $stateParam) {
            $value = $this->getCommandPropertyValue($command, $stateParam->commandProperty);
            $queries[] = new StreamQuery([$stateParam->domainIdentifierName => $value]);
        }

        return $queries;
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
