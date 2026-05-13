<?php

declare(strict_types=1);

namespace Ultra\MessageHandling\Async;

use Ultra\MessageHandling\Handler\MessageHandlerInterface;
use Ultra\MessageHandling\MessageContext;

/**
 * A handler proxy that enqueues messages instead of executing the handler.
 *
 * Registered by the compiler pass in place of the original handler service
 * when the handler method is marked with #[Asynchronous]. The router calls
 * this proxy's __invoke() like any handler — it transparently enqueues.
 */
final readonly class AsyncHandlerProxy implements MessageHandlerInterface
{
    public function __construct(
        private AsyncMessageSender $sender,
        private string $route,
    ) {}

    public function __invoke(object $message, MessageContext $context): mixed
    {
        $this->sender->enqueue(new AsyncEnvelope(
            $message,
            $context,
            AsyncHeaders::empty()->with(AsyncHeaderNames::ROUTE, $this->route),
        ));

        return null;
    }
}
