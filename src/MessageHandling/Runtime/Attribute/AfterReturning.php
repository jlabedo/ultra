<?php

declare(strict_types=1);

namespace Ultra\MessageHandling\Attribute;

/**
 * Marks an aspect service method as an after-returning advice.
 *
 * Called after the handler (and any around chain) returns successfully.
 * Cannot modify the return value or short-circuit execution — use #[Around] for that.
 *
 * Expected runtime method signature:
 *   method(object $message, \Ultra\MessageHandling\MessageContext $context, mixed $returnValue): void
 *   method(object $message, \Ultra\MessageHandling\MessageContext $context, mixed $returnValue): array
 *   method(object $message, \Ultra\MessageHandling\MessageContext $context, mixed $returnValue): ?array
 *
 * If the matcher returns a MatchResult with a non-empty config:
 *   method(object $message, \Ultra\MessageHandling\MessageContext $context, mixed $returnValue, array $config): void
 *   method(object $message, \Ultra\MessageHandling\MessageContext $context, mixed $returnValue, array $config): array
 *   method(object $message, \Ultra\MessageHandling\MessageContext $context, mixed $returnValue, array $config): ?array
 *
 * Returning an array shallow-merges that patch into the current context seen by
 * later advices and the final ProceedResult returned to outer around advices.
 *
 * The matcher defaults to Pointcut::all(...), so #[AfterReturning] applies to every handler.
 *
 * Requires PHP 8.5+ (static closure as constant expression in attribute arguments).
 *
 * Example:
 *   #[AfterReturning]
 *   public function audit(object $message, \Ultra\MessageHandling\MessageContext $context, mixed $returnValue): void
 */
#[\Attribute(\Attribute::TARGET_METHOD | \Attribute::IS_REPEATABLE)]
final class AfterReturning extends Advice {}
