<?php

declare(strict_types=1);

namespace Ultra\MappedTable;

final class InMemoryMappedOwnedRowSetAdapter implements MappedOwnedRowSetAdapter
{
    public function __construct(
        private readonly InMemoryRowDatabase $database,
        private readonly string $tableName,
    ) {}

    public function rowsForParent(array $parentKey): array
    {
        return $this->database->rowsForParent($this->tableName, $parentKey);
    }

    public function apply(OwnedRowSetUpdatePlan $plan): void
    {
        foreach ($plan->replacements as $replacement) {
            $this->database->replaceOwnedSet($this->tableName, $replacement->parentKey, $replacement->rows);
        }
    }
}
