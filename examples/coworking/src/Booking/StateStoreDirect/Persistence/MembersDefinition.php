<?php

declare(strict_types=1);

namespace App\Booking\StateStoreDirect\Persistence;

use Ultra\MappedTable\MappedColumn;
use Ultra\MappedTable\MappedRowSetDefinition;
use Ultra\MappedTable\MappedRowSetMapping;

final class MembersDefinition implements MappedRowSetDefinition
{
    public function mapping(): MappedRowSetMapping
    {
        return new MappedRowSetMapping(
            'cw_direct_members',
            [
                'id' => new MappedColumn('string'),
                'company_id' => new MappedColumn('string'),
                'email' => new MappedColumn('string'),
            ],
            ['id'],
        );
    }
}
