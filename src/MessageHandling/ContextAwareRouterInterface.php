<?php

declare(strict_types=1);

namespace Ultra\MessageHandling;

/**
 * Implemented only by generated compiled router classes.
 *
 * Public-facing router interfaces (EventBusInterface, MediatorInterface) do NOT
 * extend this interface — they remain unchanged in their public contract.
 *
 * When a handler receives a router as a dependency, the generated dispatch method
 * resolves it as `$locator->get(RouterInterface::class)->withContext($messageContext)`,
 * propagating the current dispatch context into any nested router calls.
 */
interface ContextAwareRouterInterface
{
    public function withContext(MessageContext $context): static;
}
