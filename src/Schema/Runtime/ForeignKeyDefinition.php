<?php

declare(strict_types=1);

namespace Ultra\Schema;

final readonly class ForeignKeyDefinition
{
    /** @param array<string, string> $columns Local column ID to referenced primary key column ID. */
    public function __construct(
        public string $table,
        public array $columns,
        public ForeignKeyTiming $timing = ForeignKeyTiming::NotDeferrable,
        public ForeignKeyAction $onDelete = ForeignKeyAction::NoAction,
    ) {}

    /** @internal */
    public function equivalentTo(?self $other): bool
    {
        return (
            null !== $other
            && $this->table === $other->table
            && $this->columns === $other->columns
            && $this->timing === $other->timing
            && $this->onDelete === $other->onDelete
        );
    }

    /** @return array<string, string> Local to referenced physical column names. */
    public function physicalColumns(TableDefinition $local, TableDefinition $referenced): array
    {
        $localColumns = $local->columnsById();
        $referencedColumns = $referenced->columnsById();
        $columns = [];
        foreach ($this->columns as $source => $target) {
            $columns[$localColumns[$source]->name] = $referencedColumns[$target]->name;
        }

        return $columns;
    }
}
