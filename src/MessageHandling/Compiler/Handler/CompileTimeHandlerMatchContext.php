<?php

declare(strict_types=1);

namespace Ultra\MessageHandling_Compiler\Handler;

use Ultra\MessageHandling\Attribute\AdvicePhase;
use Ultra\MessageHandling\Attribute\HandlerMatchContext;

/**
 * Compile-time implementation of HandlerMatchContext, built from HandlerDescriptor.
 */
final readonly class CompileTimeHandlerMatchContext implements HandlerMatchContext
{
    /**
     * @param class-string $messageClass
     */
    public function __construct(
        private \ReflectionMethod $reflectionMethod,
        private string $messageClass,
        private bool $isAsync,
        private bool $isSelfHandler,
        private AdvicePhase $phase,
    ) {}

    public function method(): \ReflectionMethod
    {
        return $this->reflectionMethod;
    }

    public function messageClass(): string
    {
        return $this->messageClass;
    }

    public function isAsync(): bool
    {
        return $this->isAsync;
    }

    public function isSelfHandler(): bool
    {
        return $this->isSelfHandler;
    }

    public function phase(): AdvicePhase
    {
        return $this->phase;
    }
}
