<?php

declare(strict_types=1);

namespace Ultra\MessageHandling;

interface MessageContextPropagatorInterface
{
    public function normalizeContext(MessageContext $context): MessageContext;

    public function createChildContext(MessageContext $parentContext): MessageContext;
}
