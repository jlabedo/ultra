<?php

declare(strict_types=1);

namespace Ultra\Projection;

final readonly class PartitionedDelivery extends DeliveryMode
{
    public function __construct(
        public PartitionStrategy $strategy,
    ) {}
}
