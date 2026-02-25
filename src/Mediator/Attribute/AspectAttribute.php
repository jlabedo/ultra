<?php

declare(strict_types=1);

namespace Ultra\Mediator\Attribute;

/**
 * Marker interface for PHP attributes that declare an AOP advice on a handler method.
 *
 * When the MediatorCompilerPass encounters an attribute implementing this interface
 * on a #[Handles] method, it calls getAdviceDescriptor() to retrieve the advice
 * configuration and inlines the corresponding code into the generated mediator.
 *
 * Example — a reusable #[Transactional] attribute:
 *
 *   #[Attribute(Attribute::TARGET_METHOD)]
 *   final class Transactional implements AspectAttribute {
 *       public function __construct(
 *           private readonly string $serviceId = TransactionManager::class
 *       ) {}
 *
 *       public function getAdviceDescriptor(): AdviceDescriptor {
 *           return AdviceDescriptor::around($this->serviceId, 'execute');
 *       }
 *   }
 */
interface AspectAttribute
{
    public function getAdviceDescriptor(): AdviceDescriptor;
}
