<?php

declare(strict_types=1);

namespace Ultra\Mediator_Example\OrderProcessing\Infrastructure;

use Ultra\MessageHandling\MessageContext;

final class AuditLog
{
    /** @var list<array{message: string, context: array}> */
    public array $entries = [];

    public function record(object $message, MessageContext $context): void
    {
        $this->entries[] = ['message' => $message::class, 'context' => $context->all()];
    }
}
