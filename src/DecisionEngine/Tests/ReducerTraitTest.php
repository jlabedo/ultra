<?php

declare(strict_types=1);

namespace Ultra\DecisionEngine\Tests;

use Ultra\DecisionEngine\ReducerTrait;

/** Minimal test state for ReducerTrait */
final class ReducerTraitTestState
{
    use ReducerTrait;

    public int $count = 0;
    public string $lastName = '';

    private function onIncrement(TestEventIncrement $event): void
    {
        $this->count += $event->delta;
    }

    private function onNamed(TestEventNamed $event): void
    {
        $this->lastName = $event->name;
    }
}

final readonly class TestEventIncrement
{
    public function __construct(public int $delta) {}
}

final readonly class TestEventNamed
{
    public function __construct(public string $name) {}
}

/** State that does not handle SomeUnhandledEvent */
final class ReducerTraitTestStateWithSkip
{
    use ReducerTrait;

    public int $count = 0;

    private function onIncrement(TestEventIncrement $event): void
    {
        $this->count += $event->delta;
    }
}

final readonly class SomeUnhandledEvent {}

test('fromEvents with matching events applies them in order', function () {
    $state = ReducerTraitTestState::fromEvents([
        new TestEventIncrement(2),
        new TestEventNamed('first'),
        new TestEventIncrement(3),
        new TestEventNamed('second'),
    ]);

    expect($state->count)->toBe(5);
    expect($state->lastName)->toBe('second');
});

test('fromEvents with no matching handler skips the event', function () {
    $state = ReducerTraitTestStateWithSkip::fromEvents([
        new TestEventIncrement(1),
        new SomeUnhandledEvent(),
        new TestEventIncrement(2),
    ]);

    expect($state->count)->toBe(3);
});

test('fromEvents with empty array returns default state', function () {
    $state = ReducerTraitTestState::fromEvents([]);

    expect($state->count)->toBe(0);
    expect($state->lastName)->toBe('');
});
