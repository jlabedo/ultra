<?php

declare(strict_types=1);

namespace App\Faculty\Support;

use Ultra\DecisionEngine\Attribute\DecisionHandler;
use Ultra\EventBus\EventBusInterface;
use Ultra\MessageHandling\Attribute\AfterReturning;
use Ultra\MessageHandling\Attribute\Event;
use Ultra\MessageHandling\Attribute\MatchResult;
use Ultra\MessageHandling\MessageContext;

final readonly class PublishDecisionEventsAspect
{
    public function __construct(
        private EventBusInterface $eventBus,
    ) {}

    #[AfterReturning(static function (\ReflectionMethod $method): ?MatchResult {
        return [] === $method->getAttributes(DecisionHandler::class)
            ? null
            : MatchResult::apply();
    })]
    public function publish(object $message, MessageContext $context, mixed $returnValue): void
    {
        if (! is_array($returnValue)) {
            return;
        }

        foreach ($returnValue as $candidate) {
            if (! is_object($candidate)) {
                continue;
            }

            if ([] === (new \ReflectionClass($candidate))->getAttributes(Event::class)) {
                continue;
            }

            $this->eventBus->publish($candidate, $context);
        }
    }
}
