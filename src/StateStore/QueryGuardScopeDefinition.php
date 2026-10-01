<?php

declare(strict_types=1);

namespace Ultra\StateStore;

final class QueryGuardScopeDefinition
{
    /** @var list<string>|null null means every mutation participates. */
    public ?array $columns = null;

    /** @param class-string $stateClass */
    public function __construct(public readonly string $stateClass, public readonly string $keyBy) {}

    public function whenColumnsChange(string ...$columns): self
    {
        if ([] === $columns) {
            throw new \InvalidArgumentException('A query guard needs at least one watched column.');
        }
        $this->columns = array_values(array_unique($columns));
        sort($this->columns, SORT_STRING);

        return $this;
    }
}
