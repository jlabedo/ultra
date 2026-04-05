<?php

declare(strict_types=1);

namespace Ultra\MessageHandling_Compiler\Handler;

use Ultra\MessageHandling\MessageContext;
use Ultra\MessageHandling_Compiler\HandlerDependencyExpressionResolverInterface;

/**
 * Resolves MessageContext dependencies to the runtime context variable.
 *
 * This is used when generating dispatch-phase handler classes that wrap the
 * AsyncHandlerProxy. The proxy's __invoke(object $message, MessageContext $context)
 * method needs the runtime context, not a service locator lookup.
 */
final readonly class MessageContextDependencyResolver implements HandlerDependencyExpressionResolverInterface
{
    public function resolve(string $serviceId, string $contextVar): ?string
    {
        return MessageContext::class === $serviceId ? $contextVar : null;
    }
}
