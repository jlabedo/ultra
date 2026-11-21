<?php

declare(strict_types=1);

namespace IamExample\Infrastructure\Rows;

use Ultra\MappedTable\MappedColumn;
use Ultra\MappedTable\MappedRowSetDefinition;
use Ultra\MappedTable\MappedRowSetMapping;

final class BindingsDefinition implements MappedRowSetDefinition
{
    public function mapping(): MappedRowSetMapping
    {
        return new MappedRowSetMapping(
            'iam_bindings',
            [
                'tenant_id' => new MappedColumn('string'),
                'principal_type' => new MappedColumn('string'),
                'principal_id' => new MappedColumn('string'),
                'role_id' => new MappedColumn('string'),
                'scope_type' => new MappedColumn('string'),
                'scope_id' => new MappedColumn('string'),
                'active' => new MappedColumn('bool'),
                'expires_at' => new MappedColumn('int', nullable: true),
            ],
            ['tenant_id', 'principal_type', 'principal_id', 'role_id', 'scope_type', 'scope_id'],
        );
    }
}
