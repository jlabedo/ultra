<?php

declare(strict_types=1);

namespace App\Infrastructure;

final class OrderProjection
{
    /** @var list<string> */
    public array $handledOrderIds = [];

    public function record(string $orderId): void
    {
        $this->handledOrderIds[] = $orderId;
    }
}
