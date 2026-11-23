<?php

declare(strict_types=1);

namespace App\Projection;

use Ultra\MappedTable\MappedColumn;
use Ultra\MappedTable\MappedRowSetDefinition;
use Ultra\MappedTable\MappedRowSetMapping;

final class OrderSummaryDefinition implements MappedRowSetDefinition
{
    public function mapping(): MappedRowSetMapping
    {
        return new MappedRowSetMapping(
            'order_summary',
            [
                'id' => new MappedColumn('string'),
                'customer' => new MappedColumn('string'),
                'status' => new MappedColumn('string'),
            ],
            ['id'],
        );
    }
}
