<?php

declare(strict_types=1);

namespace Ultra\Mediator_Example\OrderProcessing\Message;

use Ultra\Mediator\Attribute\Handles;
use Ultra\Mediator_Example\OrderProcessing\Infrastructure\SelfHandlingTracker;

final class SelfHandledPing
{
    public function __construct(public string $payload = '')
    {
    }

    #[Handles]
    public function handle(SelfHandlingTracker $tracker): string
    {
        $tracker->record($this->payload);

        return strtoupper($this->payload);
    }
}
