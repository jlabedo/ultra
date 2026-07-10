<?php

declare(strict_types=1);

namespace Ultra\MappedTable;

final class InMemoryMappedOwnedRowSetAdapter implements MappedOwnedRowSetAdapter
{
    public function __construct(
        private readonly InMemoryRowDatabase $database,
        private readonly string $tableName,
    ) {}

    public function rowsForParent(MappedKey $parentKey): array
    {
        return $this->database->rowsForParent($this->tableName, $parentKey->values);
    }

    public function apply(OwnedRowSetUpdatePlan $plan): void
    {
        foreach ($plan->parentMatchDeletes as $delete) {
            $this->database->deleteOwnedSetsMatching($this->tableName, $delete->partialParentKey);
        }

        foreach ($plan->replacements as $replacement) {
            $this->database->replaceOwnedSet($this->tableName, $replacement->parentKey->values, $replacement->rows);
        }
    }
}
