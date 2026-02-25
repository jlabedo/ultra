<?php

declare(strict_types=1);

namespace Ultra\Mediator;

use Ultra\Mediator\Exception\NoHandlerFound;

final class Mediator implements MediatorInterface
{
    /**
     * @param array<class-string, \Closure(object): mixed> $handlers
     */
    public function __construct(private readonly array $handlers)
    {
    }

    /**
     * Route and execute a message. Context is reserved for AOP aspects and is not passed to handlers.
     */
    public function send(object $message, array $context = []): mixed
    {
        $class = $message::class;
        if (!isset($this->handlers[$class])) {
            throw new NoHandlerFound($class);
        }

        return ($this->handlers[$class])($message);
    }
}
