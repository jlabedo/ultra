<?php

declare(strict_types=1);

namespace Ultra\MappedTable;

/** An adapter whose apply() also supports the owner collections in a row-set plan. */
interface OwnerMappedRowSetAdapter extends MappedRowSetAdapter
{
    /**
     * @param list<string> $columns
     * @return list<MappedRow>
     */
    public function rowsForOwner(MappedKey $owner, array $columns = []): array;
}
