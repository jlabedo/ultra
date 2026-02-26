<?php

declare(strict_types=1);

namespace Ultra\Mediator\Attribute;

/**
 * Marks an aspect service method as a before advice.
 *
 * Same semantics as #[Around] for matcher/priority, but the advice method
 * is called before the handler with no $proceed — it cannot short-circuit
 * execution or wrap the return value.
 *
 * Expected runtime method signature:
 *   method(object $message, array $context): void
 *
 * The matcher closure may return a non-empty 'config' array that is embedded
 * in generated code and passed as a 3rd argument:
 *   method(object $message, array $context, array $config): void
 *
 * Requires PHP 8.5+ (static closure as constant expression in attribute arguments).
 *
 * Example:
 *   #[Before(static function(\ReflectionMethod $m): ?array {
 *       return []; // apply to every handler, no config needed
 *   })]
 *   public function log(object $message, array $context): void
 */
#[\Attribute(\Attribute::TARGET_METHOD | \Attribute::IS_REPEATABLE)]
final class Before
{
    public function __construct(
        public readonly \Closure $matcher,
        public readonly int $priority = 0,
    ) {
    }
}
