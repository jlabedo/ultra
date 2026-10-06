<?php

declare(strict_types=1);

namespace Ultra\MessageHandling\Async;

use Ultra\Time\Duration;
use Ultra\UnitOfWork\UnitOfWork;

/**
 * Buffers async acknowledgements so they are flushed with the surrounding unit-of-work transaction.
 */
final class AsyncReceiverUnitOfWork implements AsyncMessageReceiver, UnitOfWork
{
    /** @var list<AsyncDelivery> */
    private array $pendingAcknowledgements = [];

    public function __construct(private readonly AsyncMessageReceiver $inner) {}

    public function reserve(Duration $leaseDuration = new Duration(seconds: 30)): ?AsyncDelivery
    {
        return $this->inner->reserve($leaseDuration);
    }

    public function acknowledge(AsyncDelivery $delivery): void
    {
        $this->pendingAcknowledgements[] = $delivery;
    }

    public function release(AsyncDelivery $delivery, ?Duration $delay = null, ?\Throwable $reason = null): void
    {
        $this->inner->release($delivery, $delay, $reason);
    }

    public function renew(AsyncDelivery $delivery, Duration $leaseDuration = new Duration(seconds: 30)): void
    {
        $this->inner->renew($delivery, $leaseDuration);
    }

    public function flush(): void
    {
        foreach ($this->pendingAcknowledgements as $delivery) {
            $this->inner->acknowledge($delivery);
        }

        $this->pendingAcknowledgements = [];
    }

    public function hasPendingChanges(): bool
    {
        return [] !== $this->pendingAcknowledgements;
    }

    public function clear(): void
    {
        $this->pendingAcknowledgements = [];
    }
}
