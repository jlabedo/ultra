<?php

declare(strict_types=1);

namespace Ultra\MessageHandling\Tests;

use PHPUnit\Framework\Attributes\CoversNamespace;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Ultra\MessageHandling\MessageContext;

#[CoversNamespace('Ultra\\MessageHandling')]
final class ContextValuesTest extends TestCase
{
    public function test_propagated_values_are_preserved_when_deriving_an_immutable_context(): void
    {
        $values = ['string' => 'value', 'int' => 1, 'float' => 1.5, 'bool' => true, 'null' => null, 'list' => ['a', 1, null]];
        $original = MessageContext::fromArray($values);
        $derived = $original->with('string', 'changed')->merge(MessageContext::fromArray(['extra' => 2]))->without('int');
        self::assertSame($values, $original->propagated()->all());
        self::assertSame('changed', $derived->get('string'));
        self::assertFalse($derived->has('int'));
        self::assertSame(2, $derived->get('extra'));
        self::assertNull($derived->get('null', 'fallback'));
        self::assertSame('fallback', $derived->get('missing', 'fallback'));
        self::assertSame(['a', 1, null], $derived->get('list'));
    }

    /** @return iterable<string, array{string}> */
    public static function localKeys(): iterable
    {
        yield 'plain' => ['service'];
        yield 'prefixed' => ['.service'];
    }

    #[DataProvider('localKeys')]
    public function test_local_values_hold_in_process_objects_and_do_not_propagate(string $key): void
    {
        $value = new \stdClass();
        $context = MessageContext::fromArray(['traceId' => 'trace-1', '.other' => $value])->withLocal($key, $value);
        self::assertSame($value, $context->local('service'));
        self::assertSame($value, $context->get('.service'));
        self::assertSame(['traceId' => 'trace-1'], $context->propagated()->all());
    }

    /** @return iterable<string, array{mixed, string}> */
    public static function invalidValues(): iterable
    {
        foreach (['object' => new \stdClass(), 'map' => ['nested' => 'map'], 'nested list' => [[1]]] as $name => $value) {
            foreach (['fromArray', 'with', 'merge'] as $operation) {
                yield $name.' via '.$operation => [$value, $operation];
            }
        }
    }

    #[DataProvider('invalidValues')]
    public function test_propagated_values_are_validated_at_each_write_boundary(mixed $value, string $operation): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Use a local key prefixed with "."');
        match ($operation) {
            'fromArray' => MessageContext::fromArray(['bad' => $value]),
            'with' => MessageContext::empty()->with('bad', $value),
            'merge' => MessageContext::empty()->merge(['bad' => $value]),
        };
    }
}
