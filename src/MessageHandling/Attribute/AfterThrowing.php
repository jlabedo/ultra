<?php

declare(strict_types=1);

namespace Ultra\MessageHandling\Attribute;

/**
 * Marks an aspect service method as an after-throwing advice.
 *
 * Called when the handler (or any around chain) throws. The exception is always
 * re-thrown after all AfterThrowing advices run. Use #[Around] to swallow exceptions.
 *
 * Expected runtime method signature:
 *   method(object $message, \Ultra\MessageHandling\MessageContext $context, \Throwable $e): void
 *   method(object $message, \Ultra\MessageHandling\MessageContext $context, \Throwable $e): array
 *   method(object $message, \Ultra\MessageHandling\MessageContext $context, \Throwable $e): ?array
 *
 * If the matcher returns a MatchResult with a non-empty config:
 *   method(object $message, \Ultra\MessageHandling\MessageContext $context, \Throwable $e, array $config): void
 *   method(object $message, \Ultra\MessageHandling\MessageContext $context, \Throwable $e, array $config): array
 *   method(object $message, \Ultra\MessageHandling\MessageContext $context, \Throwable $e, array $config): ?array
 *
 * Returning an array shallow-merges that patch into the current context seen by
 * later failure-path advices.
 *
 * The matcher defaults to Pointcut::all(...), so #[AfterThrowing] applies to every handler.
 *
 * Requires PHP 8.5+ (static closure as constant expression in attribute arguments).
 *
 * Example:
 *   #[AfterThrowing]
 *   public function reportError(object $message, \Ultra\MessageHandling\MessageContext $context, \Throwable $e): void
 */
#[\Attribute(\Attribute::TARGET_METHOD | \Attribute::IS_REPEATABLE)]
final class AfterThrowing extends Advice {}
