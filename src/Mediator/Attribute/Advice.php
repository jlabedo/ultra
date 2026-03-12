<?php

declare(strict_types=1);

namespace Ultra\Mediator\Attribute;

/**
 * Abstract base for all advice attributes: #[Around], #[Before], #[After],
 * #[AfterReturning], and #[AfterThrowing].
 *
 * Each subclass is a PHP attribute targeting methods. This base class holds
 * the two properties shared by every advice:
 *
 *  $matcher  — compile-time closure invoked once per #[Handles] method.
 *              Returns MatchResult::apply(...) to attach the advice, null to skip.
 *              Defaults to "match all" (Pointcut::all(...)).
 *  $priority — execution order when multiple advices match the same handler.
 *              Lower value = outermost / earliest. Default 0.
 *
 * Only #[Around] and #[Before] may return a MatchResult with non-empty $provides.
 * Returning $provides on an after-type advice is a compile-time LogicException.
 */
abstract class Advice
{
    public function __construct(
        public readonly \Closure $matcher = Pointcut::all(...),
        public readonly int $priority = 0,
    ) {
    }
}
