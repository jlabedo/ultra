<?php

declare(strict_types=1);

namespace App\Booking\StateStore\Persistence;

use Ultra\MappedTable\MappedColumn;
use Ultra\MappedTable\MappedRowSetDefinition;
use Ultra\MappedTable\MappedRowSetMapping;

final class CompaniesDefinition implements MappedRowSetDefinition
{
    public function mapping(): MappedRowSetMapping
    {
        return new MappedRowSetMapping(
            'cw_ss_companies',
            [
                'company_id' => new MappedColumn('string'),
                'name' => new MappedColumn('string'),
                'monthly_minutes' => new MappedColumn('int'),
            ],
            ['company_id'],
        );
    }
}
