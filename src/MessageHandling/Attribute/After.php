<?php

declare(strict_types=1);

namespace Ultra\MessageHandling\Attribute;

/**
 * Marks an aspect service method as an after (finally) advice.
 *
 * Called after the handler (and any around chain) completes, whether by returning
 * or throwing. Equivalent to a finally block — use for cleanup, resource release, etc.
 *
 * Expected runtime method signature:
 *   method(object $message, \Ultra\MessageHandling\MessageContext $context): void
 *   method(object $message, \Ultra\MessageHandling\MessageContext $context): array
 *   method(object $message, \Ultra\MessageHandling\MessageContext $context): ?array
 *
 * If the matcher returns a MatchResult with a non-empty config:
 *   method(object $message, \Ultra\MessageHandling\MessageContext $context, array $config): void
 *   method(object $message, \Ultra\MessageHandling\MessageContext $context, array $config): array
 *   method(object $message, \Ultra\MessageHandling\MessageContext $context, array $config): ?array
 *
 * Returning an array shallow-merges that patch into the current context seen by
 * later after advices and, on success, the final ProceedResult returned to outer
 * around advices.
 *
 * The matcher defaults to Pointcut::all(...), so #[After] applies to every handler.
 *
 * Requires PHP 8.5+ (static closure as constant expression in attribute arguments).
 *
 * Example:
 *   #[After]
 *   public function release(object $message, \Ultra\MessageHandling\MessageContext $context): void
 */
#[\Attribute(\Attribute::TARGET_METHOD | \Attribute::IS_REPEATABLE)]
final class After extends Advice {}
