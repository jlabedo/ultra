<?php

declare(strict_types=1);

namespace Ultra\MessageHandling\Tests;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Ultra\MessageHandling\MessageContext;

#[CoversClass(MessageContext::class)]
final class MessageContextTest extends TestCase
{
    public function test_it_accepts_serializable_propagated_values(): void
    {
        $context = MessageContext::fromArray([
            'string' => 'value',
            'int' => 1,
            'float' => 1.5,
            'bool' => true,
            'null' => null,
            'list' => ['a', 1, null],
        ]);

        self::assertSame('value', $context->get('string'));
        self::assertSame(['a', 1, null], $context->get('list'));
    }

    public function test_it_rejects_non_serializable_propagated_values(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Use a local key prefixed with "."');

        MessageContext::fromArray([
            'service' => new \stdClass(),
        ]);
    }

    public function test_local_values_can_hold_anything_and_are_available_by_local_key(): void
    {
        $value = new \stdClass();
        $context = MessageContext::empty()
            ->with('traceId', 'trace-1')
            ->withLocal('service', $value)
        ;

        self::assertSame($value, $context->local('service'));
        self::assertSame($value, $context->get('.service'));
    }

    public function test_dot_prefixed_values_are_local_and_stripped_from_propagation(): void
    {
        $local = new \stdClass();

        $context = MessageContext::fromArray([
            'traceId' => 'trace-1',
            '.local' => $local,
        ]);

        self::assertSame([
            'traceId' => 'trace-1',
        ], $context->propagated()->all());
    }

    public function test_merge_validates_incoming_values(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        MessageContext::empty()->merge([
            'bad' => ['nested' => 'map'],
        ]);
    }
}
