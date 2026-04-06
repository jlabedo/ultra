<?php

declare(strict_types=1);

namespace Ultra\MessageHandling\Attribute;

/**
 * Defines when an advice runs relative to handler execution.
 *
 * - Dispatch: runs at send/publish time. For sync handlers this is the same as
 *   Execute. For async handlers this is before enqueue.
 * - Execute: runs at execution time. For sync handlers this is the same as
 *   Dispatch. For async handlers this is at dequeue time.
 */
enum AdvicePhase
{
    case Dispatch;
    case Execute;
}
