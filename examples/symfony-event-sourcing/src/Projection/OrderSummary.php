<?php

declare(strict_types=1);

namespace App\Projection;

use App\Event\OrderPlaced;
use App\Event\OrderShipped;
use Ultra\Projection\Attribute\Projection;
use Ultra\Projection\Attribute\ProjectionHandler;
use Ultra\Projection\Attribute\ProjectionResetter;

#[Projection(name: 'order_summary', partitionBy: 'order')]
final readonly class OrderSummary
{
    public function __construct(
        private OrderSummaryRepository $summaries,
    ) {}

    #[ProjectionHandler]
    public function placed(OrderPlaced $event): void
    {
        $this->summaries->place($event->orderId, $event->customer);
    }

    #[ProjectionHandler]
    public function shipped(OrderShipped $event): void
    {
        $this->summaries->ship($event->orderId);
    }

    #[ProjectionResetter]
    public function reset(): void
    {
        $this->summaries->reset();
    }
}
