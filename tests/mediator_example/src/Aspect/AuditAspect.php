<?php

declare(strict_types=1);

namespace Ultra\Mediator_Example\OrderProcessing\Aspect;

use Ultra\Mediator_Example\OrderProcessing\Infrastructure\AuditLog;
use Ultra\MessageHandling\Attribute\Before;
use Ultra\MessageHandling\Attribute\HandlerMatchContext;
use Ultra\MessageHandling\Attribute\Handles;
use Ultra\MessageHandling\Attribute\MatchResult;
use Ultra\MessageHandling\MessageContext;

final class AuditAspect
{
    public function __construct(
        private readonly AuditLog $log,
    ) {}

    #[Before(static function (HandlerMatchContext $ctx): ?MatchResult {
        $method = $ctx->method();

        return [] !== $method->getAttributes(Handles::class) ? MatchResult::apply() : null;
    }, priority: 10)]
    public function record(object $message, MessageContext $context): void
    {
        $this->log->record($message, $context);
    }
}
