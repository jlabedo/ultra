<?php

declare(strict_types=1);

namespace Ultra\Mediator\Attribute;

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
 * Requires PHP 8.5+ (static closure as constant expression in attribute arguments).
 *
 * Example:
 *   #[After(static function(\ReflectionMethod $m): ?MatchResult {
 *       return MatchResult::apply();
 *   })]
 *   public function release(object $message, array $context): void
 */
#[\Attribute(\Attribute::TARGET_METHOD | \Attribute::IS_REPEATABLE)]
final class After
{
    public function __construct(
        public readonly \Closure $matcher,
        public readonly int $priority = 0,
    ) {
    }
}
