<?php

declare(strict_types=1);

namespace Ultra\StateStore;

/** Compile-time builder. Only its plain-data definition is retained at runtime. */
final class QueryGuardDefinition
{
    /** @var list<QueryGuardScopeDefinition> */
    public array $scopes = [];

    /** @param class-string $stateClass */
    public function watch(string $stateClass, string $keyBy): QueryGuardScopeDefinition
    {
        $scope = new QueryGuardScopeDefinition($stateClass, $keyBy);
        $this->scopes[] = $scope;

        return $scope;
    }
}
