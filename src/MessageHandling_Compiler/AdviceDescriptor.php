<?php

declare(strict_types=1);

namespace Ultra\MessageHandling_Compiler;

/**
 * Describes a single advice: the type of advice, the service to call, and the method name.
 *
 * Method signatures expected by the mediator compiler per advice type:
 *
 *   Before:         method(object $message, \Ultra\MessageHandling\MessageContext $context): void
 *   Around:         method(callable $proceed, object $message, \Ultra\MessageHandling\MessageContext $context): mixed
 *                     — $proceed signature: fn(?MessageContext $context = null, array $provided = []): mixed
 *   AfterReturning: method(object $message, \Ultra\MessageHandling\MessageContext $context, mixed $returnValue): void
 *   AfterThrowing:  method(object $message, \Ultra\MessageHandling\MessageContext $context, \Throwable $e): void
 *   After:          method(object $message, \Ultra\MessageHandling\MessageContext $context): void
 *
 * When runtimeConfig is non-empty it is embedded verbatim as a PHP literal array in the
 * generated dispatch method and passed as a final argument to the advice method:
 *   Around:         method(callable $proceed, object $message, \Ultra\MessageHandling\MessageContext $context, array $config)
 *   Before:         method(object $message, \Ultra\MessageHandling\MessageContext $context, array $config)
 *   AfterReturning: method(object $message, \Ultra\MessageHandling\MessageContext $context, mixed $returnValue, array $config)
 *   AfterThrowing:  method(object $message, \Ultra\MessageHandling\MessageContext $context, \Throwable $e, array $config)
 *   After:          method(object $message, \Ultra\MessageHandling\MessageContext $context, array $config)
 *
 * $claimedDepIndices (Around only): 0-based indices into the handler's dep-param array
 * that this advice will fill at runtime by passing extra args to $proceed.
 */
final readonly class AdviceDescriptor
{
    /**
     * @param int       $priority          lower = outer, higher = inner
     * @param string    $className         FQCN of the aspect class (used for deterministic ordering)
     * @param list<int> $claimedDepIndices 0-based indices into the handler's dep-param array
     * @param array     $runtimeConfig     serializable config embedded in generated code
     */
    public function __construct(
        public AdviceType $type,
        public int $priority,
        public string $className,
        public string $method,
        public string $serviceId,
        public array $claimedDepIndices = [],
        public array $runtimeConfig = [],
    ) {}
}
