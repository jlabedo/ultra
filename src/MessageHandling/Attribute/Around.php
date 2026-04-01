<?php

declare(strict_types=1);

namespace Ultra\MessageHandling\Attribute;

/**
 * Marks an aspect service method as an around advice.
 *
 * The $matcher closure is called at compile time for every discovered handler method
 * in the container. It receives the handler's ReflectionMethod and must return:
 *  - null                    — this aspect does not apply to that handler
 *  - MatchResult::apply(...) — this aspect applies, with optional runtime config
 *                              and optional dep-param indices to claim (provides).
 *
 * If omitted, the matcher defaults to Pointcut::all(...), meaning the advice
 * applies to every handler.
 *
 * The $priority controls execution order when multiple around advices apply to
 * the same handler. Lower priority runs outermost (first before $proceed,
 * last after). Advices with equal priority are ordered deterministically by
 * aspect class name.
 *
 * The attribute is repeatable so one aspect method can produce multiple advices
 * (e.g. an aspect that claims each entity parameter with a separate priority).
 *
 * Requires PHP 8.5+ (static closure as constant expression in attribute arguments).
 *
 * Examples:
 *   #[Around]
 *   public function execute(callable $proceed, object $message, \Ultra\MessageHandling\MessageContext $context): mixed
 *
 *   #[Around(static function(\ReflectionMethod $m): ?MatchResult {
 *       // inspect $m->getParameters(), return MatchResult::apply(...) or null
 *   }, priority: 0)]
 *   public function execute(callable $proceed, object $message, \Ultra\MessageHandling\MessageContext $context, array $config): mixed
 *
 * Continue execution with:
 *   $proceed()
 *   $proceed(context: $context->with('key', 'value'))
 *   $proceed(provided: [$resolvedDependency])
 *
 * The message object itself cannot be replaced through $proceed; only the context
 * and any values for claimed dep-param slots may be supplied.
 */
#[\Attribute(\Attribute::TARGET_METHOD | \Attribute::IS_REPEATABLE)]
final class Around extends Advice {}
