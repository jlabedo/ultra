<?php

declare(strict_types=1);

namespace IamExample\Infrastructure\Rows;

use Ultra\MappedTable\MappedColumn;
use Ultra\MappedTable\MappedRowSetDefinition;
use Ultra\MappedTable\MappedRowSetMapping;

final class MembershipsDefinition implements MappedRowSetDefinition
{
    public function mapping(): MappedRowSetMapping
    {
        return new MappedRowSetMapping(
            'iam_memberships',
            [
                'tenant_id' => new MappedColumn('string'),
                'group_id' => new MappedColumn('string'),
                'subject_id' => new MappedColumn('string'),
                'active' => new MappedColumn('bool'),
            ],
            ['tenant_id', 'group_id', 'subject_id'],
        );
    }
}
