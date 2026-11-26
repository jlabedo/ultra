<?php

declare(strict_types=1);

namespace App\Projection;

use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Ultra\MappedTable\MappedRowSet;

final readonly class OrderSummaryRepository
{
    public function __construct(
        #[Autowire(service: OrderSummarySchema::ORDER_SUMMARY)]
        private MappedRowSet $rows,
    ) {}

    public function place(string $orderId, string $customer): void
    {
        $this->rows->withKey($orderId)->insert()->setColumns(['customer' => $customer, 'status' => 'placed']);
    }

    public function ship(string $orderId): void
    {
        $this->rows->withKey($orderId)->setColumn('status', 'shipped');
    }

    public function reset(): void
    {
        $this->rows->reconcileRows([]);
    }
}
