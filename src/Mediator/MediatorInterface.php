<?php

declare(strict_types=1);

namespace Ultra\Mediator;

interface MediatorInterface
{
    public function send(object $message, array $context = []): mixed;
}
