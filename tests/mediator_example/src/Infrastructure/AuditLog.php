<?php

declare(strict_types=1);

namespace Ultra\Mediator_Example\OrderProcessing\Infrastructure;

final class AuditLog
{
    /** @var list<array{message: string, context: array}> */
    public array $entries = [];

    public function record(object $message, array $context): void
    {
        $this->entries[] = ['message' => $message::class, 'context' => $context];
    }
}
