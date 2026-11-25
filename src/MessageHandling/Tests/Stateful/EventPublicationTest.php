<?php

declare(strict_types=1);

namespace Ultra\StatefulMessageHandling\Tests;

use PHPUnit\Framework\Attributes\DataProvider;
use Ultra\Events\EventsExtension;
use Ultra\EventSourcedState\Tests\Example\Event\CourseCreated;
use Ultra\EventStore\InMemoryEventStore;
use Ultra\EventStore\StreamQuery;
use Ultra\MessageHandling\Attribute\Around;
use Ultra\MessageHandling\EventBusInterface;
use Ultra\MessageHandling\MessageContext;
use Ultra\MessageHandling\MessageContextKeys;
use Ultra\MessageHandling\ProceedResult;
use Ultra\StatefulMessageHandling\DecisionMode;
use Ultra\StatefulMessageHandling\Tests\Support\DecisionTestCase;
use Ultra\StateStore\StateStore;

final class EventPublicationTest extends DecisionTestCase
{
    #[DataProvider('storedDecisions')]
    public function test_each_event_is_published_once_after_all_returned_state_is_visible(bool $mixed): void
    {
        $app = $this->inspectedApplication($mixed ? 'mixed' : 'stored');
        if (!$mixed) {
            $app = $app->withBundle(new EventsExtension())->withClass(CourseCreated::class);
        }
        $bus = new class implements EventBusInterface {
            public StateStore $states;
            public array $publications = [];

            public function publish(object $event, array|MessageContext $context = []): void
            {
                $this->publications[] = [$event, $this->states->load(DecisionOrderState::class, 'order')];
            }
        };
        $container = $app->withService($bus, EventBusInterface::class)->boot();
        $bus->states = $container->get('test.state_store');
        $event = new CourseCreated('course', 'Math', 12);
        $state = new DecisionOrderState('order', 'paid');
        $effects = [$event, new \stdClass(), $state];
        $command = $mixed
            ? new InspectMixedDecision('course', 'order', $effects)
            : new InspectDecisionOrder('order', $effects);
        $mediator = $container->get('test.mediator');

        static::assertSame([], $mediator->send($command, [DecisionMode::CONTEXT_KEY => DecisionMode::CHECK]));
        static::assertSame([], $bus->publications);
        static::assertNull($bus->states->load(DecisionOrderState::class, 'order'));

        static::assertSame($effects, $mediator->send($command));
        static::assertCount(1, $bus->publications);
        static::assertSame($event, $bus->publications[0][0]);
        static::assertEquals($state, $bus->publications[0][1]);
        if ($mixed) {
            static::assertCount(1, $container->get(InMemoryEventStore::class)->load(StreamQuery::all())->events);
        }
    }

    public static function storedDecisions(): iterable
    {
        yield 'stored state without an event store' => [false];
        yield 'stored and event-sourced states' => [true];
    }

    public function test_appended_events_are_published_after_append_with_the_final_child_context(): void
    {
        $store = new InMemoryEventStore();
        $bus = new class($store) implements EventBusInterface {
            public array $publications = [];

            public function __construct(
                private InMemoryEventStore $store,
            ) {}

            public function publish(object $event, array|MessageContext $context = []): void
            {
                $this->publications[] = [
                    $event,
                    MessageContext::coerce($context),
                    $this->store->load(StreamQuery::all())->events,
                ];
            }
        };
        $advice = new class {
            #[Around(priority: 10)]
            public function enrich(callable $proceed, object $message, MessageContext $context): ProceedResult
            {
                return $proceed(context: $context->with('publishedBy', 'inner-advice'));
            }
        };
        $container = $this
            ->courseApplication(InspectDecisionCourse::class)
            ->withService(new DecisionService(), DecisionService::class)
            ->withService($store, InMemoryEventStore::class)
            ->withService($bus, EventBusInterface::class)
            ->withService($advice)
            ->boot();

        $effects = [new CourseCreated('course', 'Math', 12), new CourseCreated('other', 'History', 8)];
        $events = $container->get('test.mediator')->send(new InspectDecisionCourse('course', $effects), [
            'traceId' => 'trace',
            MessageContextKeys::MESSAGE_ID => 'parent',
            MessageContextKeys::CORRELATION_ID => 'correlation',
        ]);
        static::assertSame($effects, $events);
        static::assertCount(2, $bus->publications);
        foreach ($bus->publications as $index => [$published, $context, $stored]) {
            static::assertSame($events[$index], $published);
            static::assertCount(2, $stored);
            static::assertSame($context->propagated()->all(), $stored[$index]->metadata);
            static::assertSame('trace', $stored[$index]->metadata['traceId']);
            static::assertSame('inner-advice', $stored[$index]->metadata['publishedBy']);
            static::assertSame('correlation', $stored[$index]->metadata[MessageContextKeys::CORRELATION_ID]);
            static::assertSame('parent', $stored[$index]->metadata[MessageContextKeys::CAUSATION_ID]);
            static::assertNotSame('parent', $stored[$index]->metadata[MessageContextKeys::MESSAGE_ID]);
        }
        static::assertNotSame(
            $bus->publications[0][1]->get(MessageContextKeys::MESSAGE_ID),
            $bus->publications[1][1]->get(MessageContextKeys::MESSAGE_ID),
        );
    }
}
