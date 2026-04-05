<?php

declare(strict_types=1);

namespace Ultra\MessageHandling\Attribute;

/**
 * Compile-time context passed to advice matchers instead of raw \ReflectionMethod.
 *
 * Exposes handler metadata so matchers can make phase-aware and type-aware
 * decisions about whether to apply.
 */
interface HandlerMatchContext
{
    /**
     * The handler method being matched against.
     */
    public function method(): \ReflectionMethod;

    /**
     * The message class this handler handles.
     */
    public function messageClass(): string;

    /**
     * Whether the handler is marked as asynchronous.
     */
    public function isAsync(): bool;

    /**
     * Whether the handler is a self-handler (message handles itself).
     */
    public function isSelfHandler(): bool;

    /**
     * The phase the compiler is currently matching for.
     *
     * For sync handlers this is always Execute (dispatch and execute are unified).
     * For async handlers the compiler calls the matcher twice: once with Dispatch
     * (enqueue-time) and once with Execute (dequeue-time).
     */
    public function phase(): AdvicePhase;
}
