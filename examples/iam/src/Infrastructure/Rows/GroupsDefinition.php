<?php

declare(strict_types=1);

namespace IamExample\Infrastructure\Rows;

use Ultra\MappedTable\MappedColumn;
use Ultra\MappedTable\MappedRowSetDefinition;
use Ultra\MappedTable\MappedRowSetMapping;
use Ultra\MappedTable\MappedRowSetOwner;

final class GroupsDefinition implements MappedRowSetDefinition
{
    public function mapping(): MappedRowSetMapping
    {
        return new MappedRowSetMapping(
            'iam_groups',
            [
                'tenant_id' => new MappedColumn('string'),
                'group_id' => new MappedColumn('string'),
                'active' => new MappedColumn('bool'),
            ],
            ['tenant_id', 'group_id'],
            owner: new MappedRowSetOwner(TenantsDefinition::class, ['tenant_id' => 'tenant_id']),
        );
    }
}
