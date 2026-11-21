<?php

declare(strict_types=1);

namespace App\Booking\StateStore\Persistence;

use Ultra\MappedTable\MappedColumn;
use Ultra\MappedTable\MappedRowSetDefinition;
use Ultra\MappedTable\MappedRowSetMapping;

final class RoomsDefinition implements MappedRowSetDefinition
{
    public function mapping(): MappedRowSetMapping
    {
        return new MappedRowSetMapping(
            'cw_ss_rooms',
            [
                'room_id' => new MappedColumn('string'),
                'name' => new MappedColumn('string'),
            ],
            ['room_id'],
        );
    }
}
