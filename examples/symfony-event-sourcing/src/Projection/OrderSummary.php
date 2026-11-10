<?php

declare(strict_types=1);

namespace App\Projection;

use App\Event\OrderPlaced;
use App\Event\OrderShipped;
use Doctrine\DBAL\Connection;
use Ultra\MappedTable\MappedRowSet;
use Ultra\MappedTable\PostgresMappedRowSetAdapter;
use Ultra\Projection\Attribute\Projection;
use Ultra\Projection\Attribute\ProjectionHandler;
use Ultra\Projection\Attribute\ProjectionResetter;
use Ultra\Projection\Attribute\ProjectionFlush;

#[Projection(name: 'order_summary', partitionBy: 'order')]
final readonly class OrderSummary
{
    private MappedRowSet $rows;

    public function __construct(
        Connection $connection,
    ) {
        $this->rows = new MappedRowSet(new PostgresMappedRowSetAdapter($connection, 'order_summary'));
    }

    #[ProjectionHandler]
    public function placed(OrderPlaced $event): void
    {
        $this->rows->withKey($event->orderId)->insert()->setColumns([
            'customer' => $event->customer,
            'status' => 'placed',
        ]);
    }

    #[ProjectionHandler]
    public function shipped(OrderShipped $event): void
    {
        $this->rows->withKey($event->orderId)->setColumn('status', 'shipped');
    }

    #[ProjectionFlush]
    public function flush(): void
    {
        try {
            $this->rows->flush();
        } finally {
            $this->rows->clear();
        }
    }

    #[ProjectionResetter]
    public function reset(): void
    {
        $this->rows->reconcileRows([]);
        $this->flush();
    }
}
