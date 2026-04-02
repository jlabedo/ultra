<?php

declare(strict_types=1);

namespace Ultra\MessageHandling_Compiler;

/**
 * Resolves router service dependencies to `->withContext($c)` calls.
 *
 * When a handler depends on a router interface (EventBusInterface, MediatorInterface,
 * or any user-defined dispatch-attributed interface), the generated dispatch method
 * fetches the router from the service locator and calls withContext() to propagate
 * the current message context into any nested dispatches.
 */
final readonly class ContextAwareRouterDependencyResolver implements HandlerDependencyExpressionResolverInterface
{
    /** @param list<string> $routerServiceIds */
    public function __construct(private array $routerServiceIds) {}

    public function resolve(string $serviceId, string $contextVar): ?string
    {
        if (! in_array($serviceId, $this->routerServiceIds, true)) {
            return null;
        }

        return sprintf(
            '$this->locator->get(%s)->withContext(%s)',
            var_export($serviceId, true),
            $contextVar,
        );
    }
}
