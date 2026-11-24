<?php

declare(strict_types=1);

namespace App\Projection;

use App\Event\OrderPlaced;
use App\Event\OrderShipped;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Ultra\MappedTable\MappedRowSet;
use Ultra\Projection\Attribute\Projection;
use Ultra\Projection\Attribute\ProjectionHandler;
use Ultra\Projection\Attribute\ProjectionResetter;

#[Projection(name: 'order_summary', partitionBy: 'order', rowSets: [OrderSummarySchema::ORDER_SUMMARY])]
final readonly class OrderSummary
{
    public function __construct(
        #[Autowire(service: OrderSummarySchema::ORDER_SUMMARY)]
        private MappedRowSet $rows,
    ) {}

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

    #[ProjectionResetter]
    public function reset(): void
    {
        $this->rows->reconcileRows([]);
    }
}
