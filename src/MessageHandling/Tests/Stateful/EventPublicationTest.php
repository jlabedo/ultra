<?php

declare(strict_types=1);

namespace Ultra\StatefulMessageHandling\Tests;

use Ultra\EventSourcedState\Tests\Example\Event\CourseCreated;
use Ultra\EventStore\InMemoryEventStore;
use Ultra\EventStore\StreamQuery;
use Ultra\MessageHandling\Attribute\Around;
use Ultra\MessageHandling\EventBusInterface;
use Ultra\MessageHandling\MessageContext;
use Ultra\MessageHandling\MessageContextKeys;
use Ultra\MessageHandling\ProceedResult;
use Ultra\StatefulMessageHandling\Tests\Support\DecisionTestCase;

final class EventPublicationTest extends DecisionTestCase
{
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
