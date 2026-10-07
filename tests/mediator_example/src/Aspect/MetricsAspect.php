<?php

declare(strict_types=1);

namespace Ultra\Mediator_Example\OrderProcessing\Aspect;

use Ultra\Mediator_Example\OrderProcessing\Infrastructure\Metrics;
use Ultra\MessageHandling\Attribute\AfterReturning;
use Ultra\MessageHandling\Attribute\AfterThrowing;
use Ultra\MessageHandling\Attribute\HandlerMatchContext;
use Ultra\MessageHandling\Attribute\Handles;
use Ultra\MessageHandling\Attribute\MatchResult;
use Ultra\MessageHandling\MessageContext;

final class MetricsAspect
{
    public function __construct(
        private readonly Metrics $metrics,
    ) {}

    #[AfterReturning(static function (HandlerMatchContext $ctx): ?MatchResult {
        $method = $ctx->method();

        return [] !== $method->getAttributes(Handles::class) ? MatchResult::apply() : null;
    }, priority: 10)]
    public function success(object $message, MessageContext $context, mixed $result): void
    {
        $this->metrics->recordSuccess();
    }

    #[AfterThrowing(static function (HandlerMatchContext $ctx): ?MatchResult {
        $method = $ctx->method();

        return [] !== $method->getAttributes(Handles::class) ? MatchResult::apply() : null;
    }, priority: 10)]
    public function failure(object $message, MessageContext $context, \Throwable $e): void
    {
        $this->metrics->recordFailure();
    }
}
