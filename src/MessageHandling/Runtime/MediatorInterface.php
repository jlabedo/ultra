<?php

declare(strict_types=1);

namespace Ultra\MessageHandling;

use Ultra\MessageHandling\Attribute\Handles;
use Ultra\MessageHandling\Attribute\RouteMatch;
use Ultra\MessageHandling\Attribute\SelfHandler;
use Ultra\MessageHandling\Attribute\SingleDispatch;
use Ultra\MessageHandling\Exception\NoHandlerFound;

interface MediatorInterface
{
    #[SingleDispatch(
        matcher: static function (\ReflectionMethod $m): ?RouteMatch {
            if (
                [] !== $m->getAttributes(Handles::class)
                || [] !== $m->getAttributes(SelfHandler::class)
            ) {
                return RouteMatch::for();
            }

            return null;
        },
        noHandlerExceptionClass: NoHandlerFound::class,
    )]
    public function send(object $message, array|MessageContext $context = []): mixed;
}
