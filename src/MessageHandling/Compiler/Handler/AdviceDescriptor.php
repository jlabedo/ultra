<?php

declare(strict_types=1);

namespace Ultra\MessageHandling_Compiler\Handler;

/**
 * Describes a single advice: the type of advice, the service to call, and the method name.
 *
 * Method signatures expected by the mediator compiler per advice type:
 *
 *   Before:         method(object $message, \Ultra\MessageHandling\MessageContext $context): void
 *                   method(object $message, \Ultra\MessageHandling\MessageContext $context): array
 *                   method(object $message, \Ultra\MessageHandling\MessageContext $context): ?array
 *   Around:         method(callable $proceed, object $message, \Ultra\MessageHandling\MessageContext $context): \Ultra\MessageHandling\ProceedResult
 *                     — $proceed signature: fn(?MessageContext $context = null, array $provided = []): \Ultra\MessageHandling\ProceedResult
 *   AfterReturning: method(object $message, \Ultra\MessageHandling\MessageContext $context, mixed $returnValue): void
 *                   method(object $message, \Ultra\MessageHandling\MessageContext $context, mixed $returnValue): array
 *                   method(object $message, \Ultra\MessageHandling\MessageContext $context, mixed $returnValue): ?array
 *   AfterThrowing:  method(object $message, \Ultra\MessageHandling\MessageContext $context, \Throwable $e): void
 *                   method(object $message, \Ultra\MessageHandling\MessageContext $context, \Throwable $e): array
 *                   method(object $message, \Ultra\MessageHandling\MessageContext $context, \Throwable $e): ?array
 *   After:          method(object $message, \Ultra\MessageHandling\MessageContext $context): void
 *                   method(object $message, \Ultra\MessageHandling\MessageContext $context): array
 *                   method(object $message, \Ultra\MessageHandling\MessageContext $context): ?array
 *
 * When runtimeConfig is non-empty it is embedded verbatim as a PHP literal array in the
 * generated dispatch method and passed as a final argument to the advice method:
 *   Around:         method(callable $proceed, object $message, \Ultra\MessageHandling\MessageContext $context, array $config): \Ultra\MessageHandling\ProceedResult
 *   Before:         method(object $message, \Ultra\MessageHandling\MessageContext $context, array $config)
 *                   method(object $message, \Ultra\MessageHandling\MessageContext $context, array $config): array
 *                   method(object $message, \Ultra\MessageHandling\MessageContext $context, array $config): ?array
 *   AfterReturning: method(object $message, \Ultra\MessageHandling\MessageContext $context, mixed $returnValue, array $config)
 *                   method(object $message, \Ultra\MessageHandling\MessageContext $context, mixed $returnValue, array $config): array
 *                   method(object $message, \Ultra\MessageHandling\MessageContext $context, mixed $returnValue, array $config): ?array
 *   AfterThrowing:  method(object $message, \Ultra\MessageHandling\MessageContext $context, \Throwable $e, array $config)
 *                   method(object $message, \Ultra\MessageHandling\MessageContext $context, \Throwable $e, array $config): array
 *                   method(object $message, \Ultra\MessageHandling\MessageContext $context, \Throwable $e, array $config): ?array
 *   After:          method(object $message, \Ultra\MessageHandling\MessageContext $context, array $config)
 *                   method(object $message, \Ultra\MessageHandling\MessageContext $context, array $config): array
 *                   method(object $message, \Ultra\MessageHandling\MessageContext $context, array $config): ?array
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
        public bool $returnsContextPatch = false,
    ) {}
}
