<?php

declare(strict_types=1);

namespace IamExample\Infrastructure\Rows;

use Ultra\MappedTable\MappedColumn;
use Ultra\MappedTable\MappedRowSetDefinition;
use Ultra\MappedTable\MappedRowSetMapping;

final class AuditDefinition implements MappedRowSetDefinition
{
    public function mapping(): MappedRowSetMapping
    {
        return new MappedRowSetMapping(
            'iam_audit',
            [
                'entry_id' => new MappedColumn('string'),
                'tenant_id' => new MappedColumn('string'),
                'actor_id' => new MappedColumn('string'),
                'operation' => new MappedColumn('string'),
                'target' => new MappedColumn('string'),
                'occurred_at' => new MappedColumn('int'),
                'snapshot' => new MappedColumn('string'),
            ],
            ['entry_id'],
        );
    }
}
