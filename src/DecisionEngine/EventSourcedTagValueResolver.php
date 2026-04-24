<?php

declare(strict_types=1);

namespace Ultra\DecisionEngine;

use Ultra\DecisionEngine\Exception\DuplicateTagValueException;
use Ultra\DecisionEngine\Exception\MissingTagValueException;
use Ultra\DecisionEngine\Exception\UnexpectedTagValueException;
use Ultra\EventStore\DomainIdentifier;

final class EventSourcedTagValueResolver
{
    /**
     * @param list<string> $expectedTags
     *
     * @return array<string, DomainIdentifier>
     */
    public static function resolve(string $entityClass, array $expectedTags, DomainIdentifier ...$identifiers): array
    {
        $expectedTagLookup = array_fill_keys($expectedTags, true);
        $tagValues = [];

        foreach ($identifiers as $identifier) {
            if (!isset($expectedTagLookup[$identifier->dimension])) {
                throw new UnexpectedTagValueException($entityClass, $identifier->dimension, $expectedTags);
            }

            if (isset($tagValues[$identifier->dimension])) {
                throw new DuplicateTagValueException($entityClass, $identifier->dimension);
            }

            $tagValues[$identifier->dimension] = $identifier;
        }

        $missingTags = [];
        foreach ($expectedTags as $tag) {
            if (!isset($tagValues[$tag])) {
                $missingTags[] = $tag;
            }
        }

        if ([] !== $missingTags) {
            throw new MissingTagValueException($entityClass, $missingTags);
        }

        return $tagValues;
    }
}
