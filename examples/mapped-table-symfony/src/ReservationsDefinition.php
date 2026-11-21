<?php

declare(strict_types=1);

namespace MappedTableSymfonyExample;

use Ultra\MappedTable\MappedColumn;
use Ultra\MappedTable\MappedRowSetDefinition;
use Ultra\MappedTable\MappedRowSetMapping;
use Ultra\MappedTable\MappedRowSetOwner;

final class ReservationsDefinition implements MappedRowSetDefinition
{
    public function mapping(): MappedRowSetMapping
    {
        return new MappedRowSetMapping(
            'reservations',
            [
                'workshop_id' => new MappedColumn('string'),
                'reference' => new MappedColumn('string'),
                'seats' => new MappedColumn('int'),
            ],
            ['workshop_id', 'reference'],
            owner: new MappedRowSetOwner(WorkshopsDefinition::class, [
                'workshop_id' => 'id',
            ]),
        );
    }
}
