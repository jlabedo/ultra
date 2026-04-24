<?php

declare(strict_types=1);

namespace Ultra\Mediator_Example\OrderProcessing\Infrastructure;

final class SelfHandlingTracker
{
    public int $calls = 0;

    /** @var list<string> */
    public array $payloads = [];

    public function record(string $payload): void
    {
        ++$this->calls;
        $this->payloads[] = $payload;
    }
}
