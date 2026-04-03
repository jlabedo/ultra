<?php

declare(strict_types=1);

namespace Ultra\MessageHandling_Compiler\Routing;

use Ultra\MessageHandling_Compiler\HandlerDependencyExpressionResolverInterface;

/**
 * Resolves router service dependencies to contextual wrapper instantiations.
 *
 * When a handler depends on a router interface (EventBusInterface, MediatorInterface,
 * or any user-defined dispatch-attributed interface), the generated dispatch method
 * wraps the router from the service locator in a contextual wrapper that merges
 * the current message context into any nested dispatches.
 *
 * This replaces the previous ContextAwareRouterInterface / withContext() approach
 * and is decorator-safe: the wrapper delegates to whatever the container returns,
 * regardless of whether it's the compiled router, a decorator, or a test double.
 */
final readonly class ContextualRouterDependencyResolver implements HandlerDependencyExpressionResolverInterface
{
    /** @param array<string, string> $wrapperClassByServiceId Maps router service ID to generated wrapper class name */
    public function __construct(private array $wrapperClassByServiceId) {}

    public function resolve(string $serviceId, string $contextVar): ?string
    {
        $wrapperClass = $this->wrapperClassByServiceId[$serviceId] ?? null;
        if (null === $wrapperClass) {
            return null;
        }

        return sprintf(
            'new \%s($this->locator->get(%s), %s)',
            $wrapperClass,
            var_export($serviceId, true),
            $contextVar,
        );
    }
}
