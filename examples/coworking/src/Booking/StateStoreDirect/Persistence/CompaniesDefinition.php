<?php

declare(strict_types=1);

namespace App\Booking\StateStoreDirect\Persistence;

use Ultra\MappedTable\MappedColumn;
use Ultra\MappedTable\MappedRowSetDefinition;
use Ultra\MappedTable\MappedRowSetMapping;

final class CompaniesDefinition implements MappedRowSetDefinition
{
    public function mapping(): MappedRowSetMapping
    {
        return new MappedRowSetMapping(
            'cw_direct_companies',
            [
                'id' => new MappedColumn('string'),
                'name' => new MappedColumn('string'),
                'monthly_minutes' => new MappedColumn('int'),
            ],
            ['id'],
        );
    }
}
