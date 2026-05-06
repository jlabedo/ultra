<?php

declare(strict_types=1);

namespace Ultra\MessageHandling\Async;

use Ultra\Time\Duration;

interface AsyncQueueInterface
{
    public function enqueue(AsyncEnvelope $envelope, ?AsyncEnqueueOptions $options = null): AsyncMessageId;

    public function reserve(Duration $leaseDuration = new Duration(seconds: 30)): ?AsyncDelivery;

    public function acknowledge(AsyncDelivery $delivery): void;

    public function release(AsyncDelivery $delivery, ?Duration $delay = null, ?\Throwable $reason = null): void;

    public function renew(AsyncDelivery $delivery, Duration $leaseDuration = new Duration(seconds: 30)): void;
}
