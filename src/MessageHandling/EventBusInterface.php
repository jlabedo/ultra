<?php

declare(strict_types=1);

namespace Ultra\MessageHandling;

use Ultra\MessageHandling\Attribute\EventHandler;
use Ultra\MessageHandling\Attribute\MultiDispatch;
use Ultra\MessageHandling\Attribute\RouteMatch;

interface EventBusInterface
{
    #[MultiDispatch(
        matcher: static function (\ReflectionMethod $m): ?RouteMatch {
            $attrs = $m->getAttributes(EventHandler::class);
            if ([] === $attrs) {
                return null;
            }

            return RouteMatch::for(priority: $attrs[0]->newInstance()->priority);
        },
        messageTag: 'ultra_event_bus.event',
        noHandlerExceptionClass: Exception\UnknownEvent::class,
    )]
    public function publish(object $event, array|MessageContext $context = []): void;
}
