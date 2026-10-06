<?php

declare(strict_types=1);

namespace Ultra\StatefulMessageHandling\Tests;

use PHPUnit\Framework\Attributes\DataProvider;
use Ultra\EventSourcedState\Tests\Example\Event\CourseCreated;
use Ultra\EventStore\InMemoryEventStore;
use Ultra\EventStore\StreamQuery;
use Ultra\StatefulMessageHandling\Tests\Support\DecisionTestCase;

final class DecisionEffectsTest extends DecisionTestCase
{
    #[DataProvider('returnedEffects')]
    public function test_only_registered_returned_objects_become_effects_while_the_handler_result_is_preserved(string $family, string $shape): void
    {
        $container = $this->inspectedApplication($family)->boot();
        $event = new CourseCreated('course', 'Math', 12);
        $state = new DecisionOrderState('order', 'paid');
        $effect = 'stored' === $family ? $state : $event;
        $value = match ($shape) {
            'object' => $effect,
            'mixed list' => [null, 42, new \stdClass(), $event, $state, new CourseCreated('other', 'History', 8), new DecisionOrderState('other', 'confirmed')],
            'null' => null,
            'scalar' => 'no effect',
            'unregistered' => new \stdClass(),
        };
        $command = match ($family) {
            'events' => new InspectDecisionCourse('course', $value),
            'stored' => new InspectDecisionOrder('order', $value),
            'mixed' => new InspectMixedDecision('course', 'order', $value),
        };
        self::assertSame($value, $container->get('test.mediator')->send($command));
        $hasEffect = in_array($shape, ['object', 'mixed list'], true);
        if ('stored' !== $family) {
            $events = $container->get(InMemoryEventStore::class)->load(StreamQuery::all())->events;
            self::assertCount($hasEffect ? ('mixed list' === $shape ? 2 : 1) : 0, $events);
            if ($hasEffect) {
                self::assertSame('course.created', $events[0]->eventType);
                self::assertSame(['courseId' => 'course', 'name' => 'Math', 'maxCapacity' => 12], $events[0]->payload);
            }
        }
        if ('events' !== $family) {
            self::assertEquals($hasEffect ? $state : null, $container->get('test.state_store')->load(DecisionOrderState::class, 'order'));
            self::assertEquals('mixed list' === $shape ? new DecisionOrderState('other', 'confirmed') : null, $container->get('test.state_store')->load(DecisionOrderState::class, 'other'));
        }
    }

    public static function returnedEffects(): iterable
    {
        foreach (['events', 'stored'] as $family) {
            foreach (['object', 'mixed list', 'null', 'scalar', 'unregistered'] as $shape) {
                yield $family.' '.$shape => [$family, $shape];
            }
        }
        yield 'both persistence families' => ['mixed', 'mixed list'];
    }
}
