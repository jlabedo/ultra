<?php

declare(strict_types=1);

namespace App\Event;

use Ultra\Events\Attribute\DomainIdentifier;
use Ultra\Events\Attribute\Event;

#[Event('order.shipped')]
final readonly class OrderShipped
{
    public function __construct(
        #[DomainIdentifier('order')]
        public string $orderId,
    ) {}
}
