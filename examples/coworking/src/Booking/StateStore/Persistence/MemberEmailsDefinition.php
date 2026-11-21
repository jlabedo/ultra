<?php

declare(strict_types=1);

namespace App\Booking\StateStore\Persistence;

use Ultra\MappedTable\MappedColumn;
use Ultra\MappedTable\MappedRowSetDefinition;
use Ultra\MappedTable\MappedRowSetMapping;

final class MemberEmailsDefinition implements MappedRowSetDefinition
{
    public function mapping(): MappedRowSetMapping
    {
        return new MappedRowSetMapping(
            'cw_ss_member_emails',
            [
                'email' => new MappedColumn('string'),
                'member_id' => new MappedColumn('string'),
            ],
            ['email'],
        );
    }
}
