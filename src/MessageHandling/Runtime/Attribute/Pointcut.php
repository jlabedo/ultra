<?php

declare(strict_types=1);

namespace Ultra\MessageHandling\Attribute;

final readonly class Pointcut
{
    /**
     * Default matcher: applies to every handler at Execute phase.
     *
     * For sync handlers, the collector treats Execute as covering both dispatch and execute.
     * For async handlers, this only matches at dequeue time. Advices that need dispatch-time
     * execution must return MatchResult::apply(phases: [AdvicePhase::Dispatch]).
     */
    public static function all(HandlerMatchContext $ctx): MatchResult
    {
        return MatchResult::apply();
    }
}
