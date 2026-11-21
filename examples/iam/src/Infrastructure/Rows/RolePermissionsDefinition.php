<?php

declare(strict_types=1);

namespace IamExample\Infrastructure\Rows;

use Ultra\MappedTable\MappedColumn;
use Ultra\MappedTable\MappedRowSetDefinition;
use Ultra\MappedTable\MappedRowSetMapping;
use Ultra\MappedTable\MappedRowSetOwner;

final class RolePermissionsDefinition implements MappedRowSetDefinition
{
    public function mapping(): MappedRowSetMapping
    {
        return new MappedRowSetMapping(
            'iam_role_permissions',
            [
                'tenant_id' => new MappedColumn('string'),
                'role_id' => new MappedColumn('string'),
                'permission' => new MappedColumn('string'),
            ],
            ['tenant_id', 'role_id', 'permission'],
            owner: new MappedRowSetOwner(RolesDefinition::class, ['tenant_id' => 'tenant_id', 'role_id' => 'role_id']),
        );
    }
}
