<?php

declare(strict_types=1);

namespace Ultra\Projection;

abstract readonly class DeliveryMode
{
    public static function direct(): self
    {
        return new DirectDelivery();
    }

    public static function partitioned(PartitionStrategy $strategy): self
    {
        return new PartitionedDelivery($strategy);
    }
}
