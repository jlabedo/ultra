<?php

declare(strict_types=1);

namespace App\Booking\StateStoreDirect\Persistence;

use Ultra\MappedTable\MappedColumn;
use Ultra\MappedTable\MappedRowSetDefinition;
use Ultra\MappedTable\MappedRowSetMapping;
use Ultra\MappedTable\PostgresMappedColumnType;
use Ultra\MappedTable\PostgresMappedRowSetConfiguration;

final class BookingsDefinition implements MappedRowSetDefinition, PostgresMappedRowSetConfiguration
{
    public function mapping(): MappedRowSetMapping
    {
        return new MappedRowSetMapping(
            'cw_direct_bookings',
            [
                'id' => new MappedColumn('string'),
                'room_id' => new MappedColumn('string'),
                'member_id' => new MappedColumn('string'),
                'company_id' => new MappedColumn('string'),
                'start_at' => new MappedColumn('string'),
                'end_at' => new MappedColumn('string'),
                'status' => new MappedColumn('string'),
                'charged' => new MappedColumn('bool'),
            ],
            ['id'],
        );
    }

    public function postgresColumnTypes(): array
    {
        return [
            'start_at' => PostgresMappedColumnType::timestampWithTimeZone(),
            'end_at' => PostgresMappedColumnType::timestampWithTimeZone(),
        ];
    }
}
