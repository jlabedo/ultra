<?php

declare(strict_types=1);

namespace Ultra\MessageHandling\Async;

use Ultra\Time\Duration;

interface AsyncMessageReceiver
{
    public function reserve(Duration $leaseDuration = new Duration(seconds: 30)): ?AsyncDelivery;

    public function acknowledge(AsyncDelivery $delivery): void;

    /** Release the lease or quarantine the delivery when its attempt limit is reached. */
    public function release(AsyncDelivery $delivery, ?Duration $delay = null, ?\Throwable $reason = null): void;

    public function renew(AsyncDelivery $delivery, Duration $leaseDuration = new Duration(seconds: 30)): void;
}
