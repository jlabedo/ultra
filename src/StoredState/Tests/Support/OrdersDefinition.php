<?php

declare(strict_types=1);

namespace Ultra\StateStore\Tests\Support;

use Ultra\MappedTable\MappedColumn;
use Ultra\MappedTable\MappedRowSetDefinition;
use Ultra\MappedTable\MappedRowSetMapping;

final class OrdersDefinition implements MappedRowSetDefinition
{
    public function mapping(): MappedRowSetMapping
    {
        return new MappedRowSetMapping(
            'orders',
            [
                'order_id' => new MappedColumn('string'),
                'status' => new MappedColumn('string'),
                'note' => new MappedColumn('string', nullable: true),
            ],
            ['order_id'],
        );
    }
}
