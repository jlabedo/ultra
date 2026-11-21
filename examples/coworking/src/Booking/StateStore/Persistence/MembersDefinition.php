<?php

declare(strict_types=1);

namespace App\Booking\StateStore\Persistence;

use Ultra\MappedTable\MappedColumn;
use Ultra\MappedTable\MappedRowSetDefinition;
use Ultra\MappedTable\MappedRowSetMapping;

final class MembersDefinition implements MappedRowSetDefinition
{
    public function mapping(): MappedRowSetMapping
    {
        return new MappedRowSetMapping(
            'cw_ss_members',
            [
                'member_id' => new MappedColumn('string'),
                'company_id' => new MappedColumn('string'),
                'email' => new MappedColumn('string'),
            ],
            ['member_id'],
        );
    }
}
