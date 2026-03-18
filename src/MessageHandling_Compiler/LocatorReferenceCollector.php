<?php

declare(strict_types=1);

namespace Ultra\MessageHandling_Compiler;

final class LocatorReferenceCollector
{
    /** @var array<string, true> */
    private array $serviceIds = [];

    public function addService(string $serviceId): void
    {
        $this->serviceIds[$serviceId] = true;
    }

    /**
     * @param list<AdviceDescriptor> $advices
     */
    public function addHandlerDependencies(HandlerDescriptor $handler, array $advices): void
    {
        $claimedIndices = [];
        foreach ($advices as $advice) {
            foreach ($advice->claimedDepIndices as $index) {
                $claimedIndices[$index] = true;
            }
            $this->addService($advice->serviceId);
        }

        foreach ($handler->depServiceIds as $index => $depId) {
            if (! isset($claimedIndices[$index])) {
                $this->addService($depId);
            }
        }
    }

    /**
     * @return list<string>
     */
    public function all(): array
    {
        return array_keys($this->serviceIds);
    }
}
