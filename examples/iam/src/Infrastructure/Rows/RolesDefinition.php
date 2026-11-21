<?php

declare(strict_types=1);

namespace IamExample\Infrastructure\Rows;

use Ultra\MappedTable\MappedColumn;
use Ultra\MappedTable\MappedRowSetDefinition;
use Ultra\MappedTable\MappedRowSetMapping;
use Ultra\MappedTable\MappedRowSetOwner;

final class RolesDefinition implements MappedRowSetDefinition
{
    public function mapping(): MappedRowSetMapping
    {
        return new MappedRowSetMapping(
            'iam_roles',
            [
                'tenant_id' => new MappedColumn('string'),
                'role_id' => new MappedColumn('string'),
                'name' => new MappedColumn('string'),
                'active' => new MappedColumn('bool'),
            ],
            ['tenant_id', 'role_id'],
            owner: new MappedRowSetOwner(TenantsDefinition::class, ['tenant_id' => 'tenant_id']),
        );
    }
}
