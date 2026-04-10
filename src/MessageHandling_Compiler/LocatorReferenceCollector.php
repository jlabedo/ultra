<?php

declare(strict_types=1);

namespace Ultra\MessageHandling_Compiler;

use Symfony\Component\DependencyInjection\Reference;
use Ultra\MessageHandling\MessageContext;
use Ultra\MessageHandling_Compiler\Handler\HandlerDescriptor;

final class LocatorReferenceCollector
{
    /** @var array<string, true> */
    private array $serviceIds = [];

    /**
     * @param list<HandlerDependencyExpressionResolverInterface> $expressionResolvers
     */
    public function __construct(
        private readonly array $expressionResolvers = [],
    ) {}

    public function addHandlerDependencies(HandlerDescriptor $handler): void
    {
        if (HandlerDescriptor::SELF_SERVICE_ID !== $handler->serviceId) {
            $this->addService($handler->serviceId);
        }

        $claimedIndices = [];
        foreach ($handler->advices as $advice) {
            foreach ($advice->claimedDepIndices as $index) {
                $claimedIndices[$index] = true;
            }
            $this->addService($advice->serviceId);
        }

        foreach ($handler->depServiceIds as $index => $depId) {
            if (! isset($claimedIndices[$index]) && ! $this->isRuntimeOnlyResolvedDependency($depId)) {
                $this->addService($depId);
            }
        }
    }

    /**
     * @return array<string, Reference>
     */
    public function toLocatorReferences(): array
    {
        $references = [];
        foreach ($this->serviceIds as $serviceId => $_) {
            $references[$serviceId] = new Reference($serviceId);
        }

        return $references;
    }

    private function addService(string $serviceId): void
    {
        $this->serviceIds[$serviceId] = true;
    }

    private function isRuntimeOnlyResolvedDependency(string $serviceId): bool
    {
        return MessageContext::class === $serviceId && $this->isExpressionResolved($serviceId);
    }

    private function isExpressionResolved(string $serviceId): bool
    {
        foreach ($this->expressionResolvers as $resolver) {
            if (null !== $resolver->resolve($serviceId, '$c')) {
                return true;
            }
        }

        return false;
    }
}
