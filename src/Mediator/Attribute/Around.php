<?php

declare(strict_types=1);

namespace Ultra\Mediator\Attribute;

/**
 * Marks an aspect service method as an around advice.
 *
 * The $matcher closure is called at compile time for every #[Handles] method
 * in the container. It receives the handler's ReflectionMethod and must return:
 *  - null                    — this aspect does not apply to that handler
 *  - MatchResult::apply(...) — this aspect applies, with optional runtime config
 *                              and optional dep-param indices to claim (provides).
 *
 * The $priority controls execution order when multiple around advices apply to
 * the same handler. Higher priority runs outermost (first before $proceed,
 * last after). Advices with equal priority are ordered by aspect service
 * registration order.
 *
 * The attribute is repeatable so one aspect method can produce multiple advices
 * (e.g. an aspect that claims each entity parameter with a separate priority).
 *
 * Requires PHP 8.5+ (static closure as constant expression in attribute arguments).
 *
 * Example:
 *   #[Around(static function(\ReflectionMethod $m): ?MatchResult {
 *       // inspect $m->getParameters(), return MatchResult::apply(...) or null
 *   }, priority: 10)]
 *   public function execute(callable $proceed, object $message, array $context, array $config): mixed
 */
#[\Attribute(\Attribute::TARGET_METHOD | \Attribute::IS_REPEATABLE)]
final class Around extends Advice
{
}
