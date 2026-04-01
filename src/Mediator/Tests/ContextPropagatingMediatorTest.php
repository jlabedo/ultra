<?php

declare(strict_types=1);

namespace Ultra\Mediator\Tests;

use Ultra\Mediator\ContextPropagatingMediator;
use Ultra\Mediator\MediatorInterface;
use Ultra\MessageHandling\MessageContext;

final readonly class ContextAwareMessage
{
    public function __construct(public string $payload) {}
}

final class RecordingMediator implements MediatorInterface
{
    public ?object $lastMessage = null;
    public array|MessageContext $lastContext = [];

    public function __construct(
        private readonly mixed $result,
    ) {}

    public function send(object $message, array|MessageContext $context = []): mixed
    {
        $this->lastMessage = $message;
        $this->lastContext = $context;

        return $this->result;
    }
}

test('context propagating mediator merges stored context into array-based sends', function () {
    $inner = new RecordingMediator('handled');
    $message = new ContextAwareMessage('hello');
    $mediator = new ContextPropagatingMediator(
        $inner,
        MessageContext::fromArray([
            'traceId' => 'outer-trace',
            'tenant' => 'acme',
        ]),
    );

    $result = $mediator->send($message, [
        'tenant' => 'beta',
        'requestId' => 'req-1',
    ]);

    expect($result)->toBe('handled')
        ->and($inner->lastMessage)->toBe($message)
        ->and($inner->lastContext)->toBeInstanceOf(MessageContext::class)
        ->and(MessageContext::coerce($inner->lastContext)->all())->toBe([
            'traceId' => 'outer-trace',
            'tenant' => 'beta',
            'requestId' => 'req-1',
        ])
    ;
});

test('context propagating mediator also merges MessageContext inputs', function () {
    $inner = new RecordingMediator(null);
    $mediator = new ContextPropagatingMediator(
        $inner,
        MessageContext::fromArray([
            'traceId' => 'outer-trace',
            'tenant' => 'acme',
        ]),
    );

    $mediator->send(
        new ContextAwareMessage('hello'),
        MessageContext::fromArray([
            'tenant' => 'gamma',
            'feature' => 'search',
        ]),
    );

    expect($inner->lastContext)->toBeInstanceOf(MessageContext::class)
        ->and(MessageContext::coerce($inner->lastContext)->all())->toBe([
            'traceId' => 'outer-trace',
            'tenant' => 'gamma',
            'feature' => 'search',
        ])
    ;
});
