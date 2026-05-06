<?php

declare(strict_types=1);

namespace Ultra\MessageHandling\Async;

use Ultra\MessageHandling\MessageContext;

/**
 * Immutable async message envelope.
 */
final readonly class AsyncEnvelope
{
    public AsyncHeaders $headers;

    public function __construct(
        public object $message,
        public MessageContext $messageContext,
        ?AsyncHeaders $headers = null,
    ) {
        $this->headers = $headers ?? AsyncHeaders::empty();
    }
}
