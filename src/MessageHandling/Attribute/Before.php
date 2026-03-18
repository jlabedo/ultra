<?php

declare(strict_types=1);

namespace Ultra\MessageHandling\Attribute;

/**
 * Marks an aspect service method as a before advice.
 *
 * Same semantics as #[Around] for matcher/priority, but the advice method
 * is called before the handler with no $proceed — it cannot short-circuit
 * execution or wrap the return value.
 *
 * Expected runtime method signature:
 *   method(object $message, \Ultra\MessageHandling\MessageContext $context): void
 *
 * If the matcher returns a MatchResult with a non-empty config, the config
 * is embedded in generated code and passed as a 3rd argument:
 *   method(object $message, \Ultra\MessageHandling\MessageContext $context, array $config): void
 *
 * The matcher defaults to Pointcut::all(...), so #[Before] applies to every handler.
 *
 * Requires PHP 8.5+ (static closure as constant expression in attribute arguments).
 *
 * Examples:
 *   #[Before]
 *   public function log(object $message, \Ultra\MessageHandling\MessageContext $context): void
 *
 *   #[Before(static function(\ReflectionMethod $m): ?MatchResult {
 *       return MatchResult::apply(); // apply conditionally
 *   })]
 *   public function log(object $message, \Ultra\MessageHandling\MessageContext $context): void
 */
#[\Attribute(\Attribute::TARGET_METHOD | \Attribute::IS_REPEATABLE)]
final class Before extends Advice {}
