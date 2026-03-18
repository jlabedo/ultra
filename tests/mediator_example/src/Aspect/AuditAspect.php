<?php

declare(strict_types=1);

namespace Ultra\Mediator_Example\OrderProcessing\Aspect;

use Ultra\MessageHandling\Attribute\MatchResult;
use Ultra\MessageHandling\Attribute\Before;
use Ultra\Mediator\Attribute\Handles;
use Ultra\Mediator_Example\OrderProcessing\Infrastructure\AuditLog;

final class AuditAspect
{
    public function __construct(private readonly AuditLog $log)
    {
    }

    #[Before(static function (\ReflectionMethod $method): ?MatchResult {
        return [] !== $method->getAttributes(Handles::class)
            ? MatchResult::apply()
            : null;
    }, priority: 10)]
    public function record(object $message, array $context): void
    {
        $this->log->record($message, $context);
    }
}
