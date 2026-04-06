<?php

declare(strict_types=1);

namespace Ultra\MessageHandling\Async;

use Ultra\MessageHandling\MessageContext;

/**
 * Immutable envelope carrying everything needed to replay a handler invocation.
 */
final readonly class AsyncEnvelope
{
    public function __construct(
        public object $message,
        public MessageContext $messageContext,
        /** The container service ID of the original handler (before async swap). */
        public string $handlerServiceId,
    ) {}
}
