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
 *   method(object $message, array $context): void
 *
 * If the matcher returns a MatchResult with a non-empty config:
 *   method(object $message, array $context, array $config): void
 *
 * The matcher defaults to Pointcut::all(...), so #[After] applies to every handler.
 *
 * Requires PHP 8.5+ (static closure as constant expression in attribute arguments).
 *
 * Example:
 *   #[After]
 *   public function release(object $message, array $context): void
 */
#[\Attribute(\Attribute::TARGET_METHOD | \Attribute::IS_REPEATABLE)]
final class After extends Advice {}
