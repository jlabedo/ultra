<?php

declare(strict_types=1);

namespace Ultra\Mediator\Tests;

use Ultra\Mediator\EmptyMediator;
use Ultra\Mediator\Exception\NoHandlerFound;

final readonly class UnhandledMessage
{
    public function __construct(public string $payload) {}
}

test('empty mediator throws NoHandlerFound for any message', function () {
    $mediator = new EmptyMediator();

    expect(fn () => $mediator->send(new UnhandledMessage('ping')))
        ->toThrow(NoHandlerFound::class, 'No handler found for message "'.UnhandledMessage::class.'"')
    ;
});
