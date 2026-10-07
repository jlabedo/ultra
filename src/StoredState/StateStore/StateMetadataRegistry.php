<?php

declare(strict_types=1);

namespace Ultra\StateStore;

use Ultra\StateStore\Exception\UnknownStateException;

/** @phpstan-import-type StateMetadataDefinition from StateMetadata */
final readonly class StateMetadataRegistry
{
    /** @var array<class-string, StateMetadata> */
    private array $metadata;

    /**
     * @param array<class-string, StateMetadataDefinition|StateMetadata> $metadata
     */
    public function __construct(array $metadata)
    {
        $resolved = [];

        foreach ($metadata as $stateClass => $definition) {
            $resolved[$stateClass] = $definition instanceof StateMetadata
                ? $definition
                : StateMetadata::fromArray($definition);
        }

        $this->metadata = $resolved;
    }

    /**
     * @template T of object
     *
     * @param class-string<T> $stateClass
     */
    public function metadataFor(string $stateClass): StateMetadata
    {
        $metadata = $this->metadata[$stateClass] ?? null;
        if (!$metadata instanceof StateMetadata) {
            throw new UnknownStateException($stateClass);
        }

        return $metadata;
    }

    /**
     * @param class-string $stateClass
     */
    public function has(string $stateClass): bool
    {
        return isset($this->metadata[$stateClass]);
    }
}
