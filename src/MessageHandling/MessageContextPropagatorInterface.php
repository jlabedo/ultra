<?php

declare(strict_types=1);

namespace Ultra\MessageHandling;

interface MessageContextPropagatorInterface
{
    /**
     * Creates the context for the current dispatch from the provided context.
     *
     * The input may be a root context supplied by the caller or a context merged
     * from a parent dispatch. Implementations may derive message identifiers and
     * related metadata for the current dispatch from that input.
     */
    public function createDispatchContext(MessageContext $context): MessageContext;
}
