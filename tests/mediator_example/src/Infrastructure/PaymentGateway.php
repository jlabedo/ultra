<?php

declare(strict_types=1);

namespace Ultra\Mediator_Example\OrderProcessing\Infrastructure;

final class PaymentGateway
{
    public bool $shouldFail = false;

    public function charge(string $orderId, int $amount): void
    {
        if ($this->shouldFail) {
            throw new \RuntimeException('payment failed');
        }
    }
}
