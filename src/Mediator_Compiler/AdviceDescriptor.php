<?php

declare(strict_types=1);

namespace Ultra\Mediator_Compiler;

/**
 * Describes a single advice: the type of advice, the service to call, and the method name.
 *
 * Method signatures expected by the mediator compiler per advice type:
 *
 *   Before:         method(object $message, array $context): void
 *   Around:         method(callable $proceed, object $message, array $context): mixed
 *                     — $proceed is fn(object $message, array $context, ...$provided): mixed
 *   AfterReturning: method(object $message, array $context, mixed $returnValue): void
 *   AfterThrowing:  method(object $message, array $context, \Throwable $e): void
 *   After:          method(object $message, array $context): void
 *
 * When runtimeConfig is non-empty it is embedded verbatim as a PHP literal array in the
 * generated dispatch method and passed as a final argument to the advice method:
 *   Around:         method(callable $proceed, object $message, array $context, array $config)
 *   Before:         method(object $message, array $context, array $config)
 *   AfterReturning: method(object $message, array $context, mixed $returnValue, array $config)
 *   AfterThrowing:  method(object $message, array $context, \Throwable $e, array $config)
 *   After:          method(object $message, array $context, array $config)
 *
 * $claimedDepIndices (Around only): 0-based indices into the handler's dep-param array
 * that this advice will fill at runtime by passing extra args to $proceed.
 */
final readonly class AdviceDescriptor
{
    /**
     * @param list<int> $claimedDepIndices  0-based indices into the handler's dep-param array
     * @param array     $runtimeConfig      serializable config embedded in generated code
     */
    public function __construct(
        public AdviceType $type,
        public string $serviceId,
        public string $method,
        public array $claimedDepIndices = [],
        public array $runtimeConfig = [],
    ) {
    }

    public static function before(string $serviceId, string $method): self
    {
        return new self(AdviceType::Before, $serviceId, $method);
    }

    public static function around(string $serviceId, string $method): self
    {
        return new self(AdviceType::Around, $serviceId, $method);
    }

    public static function afterReturning(string $serviceId, string $method): self
    {
        return new self(AdviceType::AfterReturning, $serviceId, $method);
    }

    public static function afterThrowing(string $serviceId, string $method): self
    {
        return new self(AdviceType::AfterThrowing, $serviceId, $method);
    }

    public static function after(string $serviceId, string $method): self
    {
        return new self(AdviceType::After, $serviceId, $method);
    }
}
