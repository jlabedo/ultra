<?php

declare(strict_types=1);

namespace Ultra\MessageHandling\Tests;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Ultra\MessageHandling\MessageContext;
use Ultra\MessageHandling\MessageContextKeys;
use Ultra\MessageHandling\MessageContextPropagator;

#[CoversClass(MessageContextPropagator::class)]
final class MessageContextPropagatorTest extends TestCase
{
    public function test_it_normalizes_a_context_without_ids(): void
    {
        $propagator = new MessageContextPropagator();

        $context = $propagator->normalizeContext(MessageContext::empty());

        self::assertNotSame('', $context->get(MessageContextKeys::MESSAGE_ID));
        self::assertSame(
            $context->get(MessageContextKeys::MESSAGE_ID),
            $context->get(MessageContextKeys::CORRELATION_ID),
        );
        self::assertFalse($context->has(MessageContextKeys::CAUSATION_ID));
    }

    public function test_it_preserves_existing_ids_when_normalizing_a_context(): void
    {
        $propagator = new MessageContextPropagator();

        $context = MessageContext::fromArray([
            MessageContextKeys::MESSAGE_ID => 'message-1',
            MessageContextKeys::CORRELATION_ID => 'correlation-1',
            MessageContextKeys::CAUSATION_ID => 'causation-1',
        ]);

        self::assertSame($context->all(), $propagator->normalizeContext($context)->all());
    }

    public function test_it_creates_a_child_context_from_a_root_context(): void
    {
        $propagator = new MessageContextPropagator();

        $root = $propagator->normalizeContext(MessageContext::empty());
        $child = $propagator->createChildContext($root);

        self::assertNotSame(
            $root->get(MessageContextKeys::MESSAGE_ID),
            $child->get(MessageContextKeys::MESSAGE_ID),
        );
        self::assertSame(
            $root->get(MessageContextKeys::CORRELATION_ID),
            $child->get(MessageContextKeys::CORRELATION_ID),
        );
        self::assertSame(
            $root->get(MessageContextKeys::MESSAGE_ID),
            $child->get(MessageContextKeys::CAUSATION_ID),
        );
    }

    public function test_it_creates_a_child_context_from_an_existing_child_context(): void
    {
        $propagator = new MessageContextPropagator();

        $root = $propagator->normalizeContext(MessageContext::empty());
        $child = $propagator->createChildContext($root);
        $grandchild = $propagator->createChildContext($child);

        self::assertNotSame(
            $child->get(MessageContextKeys::MESSAGE_ID),
            $grandchild->get(MessageContextKeys::MESSAGE_ID),
        );
        self::assertSame(
            $root->get(MessageContextKeys::CORRELATION_ID),
            $grandchild->get(MessageContextKeys::CORRELATION_ID),
        );
        self::assertSame(
            $child->get(MessageContextKeys::MESSAGE_ID),
            $grandchild->get(MessageContextKeys::CAUSATION_ID),
        );
    }
}
