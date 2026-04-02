<?php

declare(strict_types=1);

namespace Ultra\Mediator_Example\OrderProcessing\Aspect;

use Ultra\Mediator_Example\OrderProcessing\Infrastructure\CleanupTracker;
use Ultra\MessageHandling\Attribute\After;
use Ultra\MessageHandling\Attribute\Handles;
use Ultra\MessageHandling\Attribute\MatchResult;
use Ultra\MessageHandling\MessageContext;

final class CleanupAspect
{
    public function __construct(private readonly CleanupTracker $cleanup)
    {
    }

    #[After(static function (\ReflectionMethod $method): ?MatchResult {
        return [] !== $method->getAttributes(Handles::class)
            ? MatchResult::apply()
            : null;
    }, priority: -5)]
    public function after(object $message, MessageContext $context): void
    {
        $this->cleanup->cleanup();
    }
}
