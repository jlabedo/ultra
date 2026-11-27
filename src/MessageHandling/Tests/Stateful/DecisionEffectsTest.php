<?php

declare(strict_types=1);

namespace Ultra\StatefulMessageHandling\Tests;

use PHPUnit\Framework\Attributes\DataProvider;
use Ultra\Events\EventsExtension;
use Ultra\EventSourcedState\Tests\Example\Event\CourseCreated;
use Ultra\EventStore\EventStore;
use Ultra\EventStore\InMemoryEventStore;
use Ultra\EventStore\StreamQuery;
use Ultra\EventStore\Tests\Support\RecordingEventStore;
use Ultra\MessageHandling\EventBusInterface;
use Ultra\StatefulMessageHandling\DecisionMode;
use Ultra\StatefulMessageHandling\Tests\Support\DecisionTestCase;

final class DecisionEffectsTest extends DecisionTestCase
{
    public function test_decision_without_state_saves_mapped_state_and_appends_only_events_with_identifiers(): void
    {
        $store = new RecordingEventStore(new InMemoryEventStore());
        $bus = new DecisionEventBus();
        $container = $this
            ->storedApplication(InspectDecisionWithoutState::class)
            ->withBundle(new EventsExtension())
            ->withClass(CourseCreated::class, DecisionNotification::class, DecisionRecorded::class)
            ->withService($store, EventStore::class)
            ->withService($bus, EventBusInterface::class)
            ->boot();
        $state = new DecisionOrderState('order', 'paid');
        $notification = new DecisionNotification('Order paid');
        $event = new CourseCreated('course', 'Math', 12);
        $record = new DecisionRecorded('order');
        $effects = [$notification, $event, $record, $state];
        $command = new InspectDecisionWithoutState($effects);
        $mediator = $container->get('test.mediator');

        static::assertSame([], $mediator->send($command, [DecisionMode::CONTEXT_KEY => DecisionMode::CHECK]));
        static::assertNull($container->get('test.state_store')->load(DecisionOrderState::class, 'order'));
        static::assertSame([], $store->appendCalls);
        static::assertSame([], $bus->events);

        static::assertSame($effects, $mediator->send($command));
        static::assertEquals($state, $container->get('test.state_store')->load(DecisionOrderState::class, 'order'));
        static::assertCount(1, $store->appendCalls);
        static::assertSame([], $store->appendCalls[0]['appendConditions']);
        static::assertSame(
            ['course.created', 'decision.recorded'],
            array_map(
                static fn(\Ultra\EventStore\Event $event): string => $event->eventType,
                $store->load(StreamQuery::all())->events,
            ),
        );
        static::assertSame([$notification, $event, $record], $bus->events);
    }

    #[DataProvider('returnedEffects')]
    public function test_only_registered_returned_objects_become_effects_while_the_handler_result_is_preserved(
        string $family,
        string $shape,
    ): void {
        $container = $this->inspectedApplication($family)->boot();
        $event = new CourseCreated('course', 'Math', 12);
        $state = new DecisionOrderState('order', 'paid');
        $effect = 'stored' === $family ? $state : $event;
        $value = match ($shape) {
            'object' => $effect,
            'mixed list' => [
                null,
                42,
                new \stdClass(),
                $event,
                $state,
                new CourseCreated('other', 'History', 8),
                new DecisionOrderState('other', 'confirmed'),
            ],
            'null' => null,
            'scalar' => 'no effect',
            'unregistered' => new \stdClass(),
        };
        $command = match ($family) {
            'events' => new InspectDecisionCourse('course', $value),
            'stored' => new InspectDecisionOrder('order', $value),
            'mixed' => new InspectMixedDecision('course', 'order', $value),
        };
        static::assertSame($value, $container->get('test.mediator')->send($command));
        $hasEffect = in_array($shape, ['object', 'mixed list'], true);
        if ('stored' !== $family) {
            $events = $container->get(InMemoryEventStore::class)->load(StreamQuery::all())->events;
            static::assertCount($hasEffect ? ('mixed list' === $shape ? 2 : 1) : 0, $events);
            if ($hasEffect) {
                static::assertSame('course.created', $events[0]->eventType);
                static::assertSame(
                    ['courseId' => 'course', 'name' => 'Math', 'maxCapacity' => 12],
                    $events[0]->payload,
                );
            }
        }
        if ('events' !== $family) {
            static::assertEquals(
                $hasEffect ? $state : null,
                $container->get('test.state_store')->load(DecisionOrderState::class, 'order'),
            );
            static::assertEquals(
                'mixed list' === $shape ? new DecisionOrderState('other', 'confirmed') : null,
                $container->get('test.state_store')->load(DecisionOrderState::class, 'other'),
            );
        }
    }

    public static function returnedEffects(): iterable
    {
        foreach (['events', 'stored'] as $family) {
            foreach (['object', 'mixed list', 'null', 'scalar', 'unregistered'] as $shape) {
                yield $family . ' ' . $shape => [$family, $shape];
            }
        }
        yield 'both persistence families' => ['mixed', 'mixed list'];
    }
}
