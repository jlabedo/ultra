<?php

declare(strict_types=1);

namespace App\Projection;

use App\Event\OrderPlaced;
use App\Event\OrderShipped;
use Doctrine\DBAL\Connection;
use Ultra\MappedTable\MappedRowSet;
use Ultra\MappedTable\MappedTableUnitOfWork;
use Ultra\MappedTable\PostgresMappedTableAdapter;
use Ultra\Projection\Attribute\Projection;
use Ultra\Projection\Attribute\ProjectionHandler;
use Ultra\Projection\Attribute\ProjectionResetter;
use Ultra\UnitOfWork\UnitOfWork;

#[Projection(name: 'order_summary', partitionBy: 'order')]
final readonly class OrderSummary implements UnitOfWork
{
    private MappedRowSet $rows;

    private MappedTableUnitOfWork $persistence;

    public function __construct(Connection $connection)
    {
        $adapter = new PostgresMappedTableAdapter($connection);
        $this->rows = $adapter->create('order_summary');
        $this->persistence = new MappedTableUnitOfWork(['order_summary' => $this->rows], $adapter);
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

    public function hasPendingChanges(): bool
    {
        return $this->persistence->hasPendingChanges();
    }

    public function clear(): void
    {
        $this->persistence->clear();
    }

    public function flush(): void
    {
        $this->persistence->flush();
    }

    #[ProjectionResetter]
    public function reset(): void
    {
        $this->rows->reconcileRows([]);
    }
}
