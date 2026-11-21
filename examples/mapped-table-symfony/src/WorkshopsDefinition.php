<?php

declare(strict_types=1);

namespace MappedTableSymfonyExample;

use Ultra\MappedTable\MappedColumn;
use Ultra\MappedTable\MappedRowSetDefinition;
use Ultra\MappedTable\MappedRowSetMapping;

final class WorkshopsDefinition implements MappedRowSetDefinition
{
    public function mapping(): MappedRowSetMapping
    {
        return new MappedRowSetMapping(
            'workshops',
            [
                'id' => new MappedColumn('string'),
                'title' => new MappedColumn('string'),
                'capacity' => new MappedColumn('int'),
            ],
            ['id'],
        );
    }
}
