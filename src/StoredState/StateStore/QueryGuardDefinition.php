<?php

declare(strict_types=1);

namespace Ultra\StateStore;

/** Compile-time builder. Only its plain-data definition is retained at runtime. */
final class QueryGuardDefinition
{
    /** @var list<QueryGuardScopeDefinition> */
    public array $scopes = [];

    /** @param class-string $stateClass */
    public function watch(string $stateClass, string $scopeBy): QueryGuardScopeDefinition
    {
        $scope = new QueryGuardScopeDefinition($stateClass, $scopeBy);
        $this->scopes[] = $scope;

        return $scope;
    }

    /** @return list<array{tableKey: string, keyBy: string, columns: list<string>|null}> */
    public function compile(StateMetadataRegistry $metadata): array
    {
        $rules = [];
        foreach ($this->scopes as $scope) {
            if (!$metadata->has($scope->stateClass)) {
                throw new \LogicException('Query guard watches an unregistered state: ' . $scope->stateClass);
            }
            $state = $metadata->metadataFor($scope->stateClass);
            $paths = $state->propertyColumns;
            foreach ($state->idPropertyColumns as $property => $column) {
                $paths[$state->idScalar ? $state->idProperty : $state->idProperty . '.' . $property] = $column;
            }
            foreach ($state->embedded as $embedded) {
                foreach ($embedded->propertyColumns as $property => $column) {
                    $paths[$embedded->property . '.' . $property] = $column;
                }
            }
            $resolve = static fn(string $path): string => $paths[$path] ?? throw new \LogicException(sprintf(
                'Unknown mapped property "%s" on state "%s".',
                $path,
                $state->stateClass,
            ));
            $keyBy = $resolve($scope->scopeBy);
            $columns = null;
            $collections = array_keys($state->collections);
            if (null !== $scope->properties) {
                $columns = [];
                $collections = [];
                foreach ($scope->properties as $property) {
                    if (isset($state->collections[$property])) {
                        $collections[] = $property;

                        continue;
                    }
                    $columns[] = $resolve($property);
                }
                $columns = array_values(array_unique($columns));
                sort($columns, SORT_STRING);
            }
            $rules[] = ['tableKey' => $state->tableKey, 'keyBy' => $keyBy, 'columns' => $columns];
            foreach ($collections as $property) {
                $collection = $state->collections[$property];
                // Owner identifiers are available in every item key and even in an empty reconciliation.
                $ownerField = $state->idScalar
                    ? $state->idProperty
                    : substr($scope->scopeBy, strlen($state->idProperty) + 1);
                if (
                    !str_starts_with($scope->scopeBy, $state->idProperty . '.')
                    && $scope->scopeBy !== $state->idProperty
                ) {
                    throw new \LogicException(
                        'Collection query guards require scopeBy to reference an owner identifier property.',
                    );
                }
                $ownerColumn = $collection->ownerPropertyColumns[$ownerField] ?? throw new \LogicException(
                    'Collection query guard scope is not mapped in the owner identifier.',
                );
                $rules[] = ['tableKey' => $collection->tableKey, 'keyBy' => $ownerColumn, 'columns' => null];
            }
        }
        usort($rules, static fn(array $left, array $right): int => $left <=> $right);

        return array_values(array_unique($rules, SORT_REGULAR));
    }
}
