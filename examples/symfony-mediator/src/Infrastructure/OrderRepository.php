<?php

declare(strict_types=1);

namespace App\Infrastructure;

final class OrderRepository
{
    /** @var list<string> */
    public array $savedOrderIds = [];

    public function save(string $orderId): void
    {
        $this->savedOrderIds[] = $orderId;
    }
}
