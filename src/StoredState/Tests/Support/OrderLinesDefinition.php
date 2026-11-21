<?php

declare(strict_types=1);

namespace Ultra\StateStore\Tests\Support;

use Ultra\MappedTable\MappedColumn;
use Ultra\MappedTable\MappedRowSetDefinition;
use Ultra\MappedTable\MappedRowSetMapping;
use Ultra\MappedTable\MappedRowSetOwner;

final class OrderLinesDefinition implements MappedRowSetDefinition
{
    public function mapping(): MappedRowSetMapping
    {
        return new MappedRowSetMapping(
            'order_lines',
            [
                'order_id' => new MappedColumn('string'),
                'line_id' => new MappedColumn('string'),
                'sku' => new MappedColumn('string'),
                'quantity' => new MappedColumn('int'),
            ],
            ['order_id', 'line_id'],
            owner: new MappedRowSetOwner(OrdersDefinition::class, ['order_id' => 'order_id']),
        );
    }
}
