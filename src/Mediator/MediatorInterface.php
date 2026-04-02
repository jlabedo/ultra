<?php

declare(strict_types=1);

namespace Ultra\Mediator;

use Ultra\Mediator\Attribute\Handles;
use Ultra\Mediator\Attribute\SelfHandler;
use Ultra\MessageHandling\Attribute\RouteMatch;
use Ultra\MessageHandling\Attribute\SingleDispatch;
use Ultra\MessageHandling\MessageContext;

interface MediatorInterface
{
    #[SingleDispatch(
        matcher: static function (\ReflectionMethod $m): ?RouteMatch {
            if ([] !== $m->getAttributes(Handles::class)) {
                return RouteMatch::for();
            }
            if ([] !== $m->getAttributes(SelfHandler::class)) {
                return RouteMatch::selfHandler();
            }

            return null;
        },
        noHandlerExceptionClass: \Ultra\Mediator\Exception\NoHandlerFound::class,
    )]
    public function send(object $message, array|MessageContext $context = []): mixed;
}
