<?php

declare(strict_types=1);

namespace Ultra\StateStore;

final class QueryGuardScopeDefinition
{
    /** @var list<string>|null null means every mutation participates. */
    public ?array $properties = null;

    /** @param class-string $stateClass */
    public function __construct(public readonly string $stateClass, public readonly string $scopeBy) {}

    public function whenPropertiesChange(string ...$properties): self
    {
        if ([] === $properties || in_array('', $properties, true)) {
            throw new \InvalidArgumentException('A query guard needs at least one non-empty property name.');
        }
        $this->properties = array_values(array_unique([...($this->properties ?? []), ...$properties]));
        sort($this->properties, SORT_STRING);

        return $this;
    }
}
