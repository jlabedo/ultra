<?php

declare(strict_types=1);

namespace Ultra\StatefulMessageHandling\Tests;

use Ultra\StatefulMessageHandling\Attribute\DecisionHandler;
use Ultra\StatefulMessageHandling\Attribute\BindTo;
use Ultra\StatefulMessageHandling\Exception\InvariantViolation;
use Ultra\StatefulMessageHandling\Tests\Example\Command\CreateCourseCommand;
use Ultra\StatefulMessageHandling\Tests\Example\Command\SubscribeStudentToCourseCommand;
use Ultra\StatefulMessageHandling\Tests\Example\Command\UnsubscribeStudentFromCourseCommand;
use Ultra\EventSourcedState\Tests\Example\Event\CourseCreated;
use Ultra\EventSourcedState\Tests\Example\Event\StudentSubscribed;
use Ultra\EventSourcedState\Tests\Example\Event\StudentUnsubscribed;
use Ultra\EventSourcedState\Tests\Example\State\CourseCapacityState;
use Ultra\EventSourcedState\Tests\Example\State\StudentSubscriptionState;
use Ultra\StatefulMessageHandling\Tests\Support\StatefulMessageHandlingFunctionalTestCase;
use Ultra\StatefulMessageHandling\Tests\Support\StatefulMessageHandlingTestContainer;
use Ultra\StatefulMessageHandling\Violation;
use Ultra\EventStore\DomainIdentifier;
use Ultra\EventStore\StreamQuery;
use Ultra\MessageHandling\Attribute\Around;
use Ultra\MessageHandling\Attribute\Handles;
use Ultra\MessageHandling\Attribute\SelfHandler;
use Ultra\MessageHandling\EventBusInterface;
use Ultra\MessageHandling\MessageContext;
use Ultra\MessageHandling\MessageContextKeys;
use Ultra\MessageHandling\ProceedResult;
use Ultra\Testing\UltraLight;

final readonly class NestedCourseReference
{
    public function __construct(
        public string $courseId,
    ) {}
}

final readonly class CreateNestedCourseCommand
{
    public function __construct(
        public NestedCourseReference $reference,
        public string $name,
        public int $maxCapacity,
    ) {}

    #[SelfHandler]
    #[DecisionHandler]
    public function decide(
        #[BindTo('reference.courseId')]
        CourseCapacityState $course,
    ): array {
        if ($course->exists()) {
            throw new \InvalidArgumentException('Course already exists');
        }

        return [new CourseCreated($this->reference->courseId, $this->name, $this->maxCapacity)];
    }
}

final readonly class CreateCourseWithSeparateHandlerCommand
{
    public function __construct(
        public string $courseId,
        public string $name,
        public int $maxCapacity,
    ) {}
}

final readonly class CreateCourseWithSeparateHandler
{
    #[Handles]
    #[DecisionHandler]
    public function handle(
        CreateCourseWithSeparateHandlerCommand $command,
        CourseCapacityState $course,
    ): array {
        return [new CourseCreated($command->courseId, $command->name, $command->maxCapacity)];
    }
}

/**
 * @internal
 *
 * @coversNothing
 */
final class StatefulMessageHandlingAspectTest extends StatefulMessageHandlingFunctionalTestCase
{
    public function test_bind_to_resolves_nested_property_paths_for_event_sourced_state(): void
    {
        $container = $this->ultraLight()->boot();

        $events = $container->mediator()->send(new CreateNestedCourseCommand(
            new NestedCourseReference('nested-course'),
            'Nested course',
            12,
        ));

        self::assertEquals([new CourseCreated('nested-course', 'Nested course', 12)], $events);
        self::assertCount(1, $container->eventStore()->load(self::courseStreamQuery('nested-course'))->events);
    }

    public function test_it_supports_an_event_sourced_decision_handler_on_a_separate_handler(): void
    {
        $container = $this->ultraLight()->boot();
        $command = new CreateCourseWithSeparateHandlerCommand('separate-course', 'Separate course', 12);

        self::assertSame([], $container->mediator()->send($command, ['decision_mode' => 'check']));

        $events = $container->mediator()->send($command);

        self::assertEquals([new CourseCreated('separate-course', 'Separate course', 12)], $events);
        self::assertCount(1, $container->eventStore()->load(self::courseStreamQuery('separate-course'))->events);
    }

    public function test_it_creates_a_course_and_subscribes_a_student_through_the_mediator(): void
    {
        $container = $this->ultraLight()->boot();
        $mediator = $container->mediator();
        $store = $container->eventStore();

        $createEvents = $mediator->send(new CreateCourseCommand('math-101', 'Math', 10));
        $subscribeEvents = $mediator->send(new SubscribeStudentToCourseCommand('math-101', 'alice'));

        self::assertCount(1, $createEvents);
        self::assertInstanceOf(CourseCreated::class, $createEvents[0]);
        self::assertCount(1, $subscribeEvents);
        self::assertInstanceOf(StudentSubscribed::class, $subscribeEvents[0]);
        self::assertCount(2, $store->load(self::courseStreamQuery('math-101'))->events);
    }

    public function test_it_returns_the_emitted_events_from_the_decision_handler(): void
    {
        $container = $this->ultraLight()->boot();
        $mediator = $container->mediator();

        $events = $mediator->send(new CreateCourseCommand('c1', 'Course 1', 5));

        self::assertEquals(
            [new CourseCreated('c1', 'Course 1', 5)],
            $events,
        );
    }

    public function test_it_throws_invariant_violation_when_the_course_is_full(): void
    {
        $container = $this->ultraLight()->boot();
        $mediator = $container->mediator();
        $store = $container->eventStore();

        $mediator->send(new CreateCourseCommand('math-101', 'Math', 1));
        $mediator->send(new SubscribeStudentToCourseCommand('math-101', 'alice'));

        $this->expectException(InvariantViolation::class);

        try {
            $mediator->send(new SubscribeStudentToCourseCommand('math-101', 'bob'));
        } finally {
            self::assertCount(2, $store->load(self::courseStreamQuery('math-101'))->events);
        }
    }

    public function test_it_throws_when_creating_a_course_that_already_exists(): void
    {
        $container = $this->ultraLight()->boot();
        $mediator = $container->mediator();

        $mediator->send(new CreateCourseCommand('math-101', 'Math', 10));

        $this->expectException(\InvalidArgumentException::class);

        $mediator->send(new CreateCourseCommand('math-101', 'Other', 5));
    }

    public function test_it_hydrates_empty_state_objects_when_streams_are_empty(): void
    {
        $container = $this->ultraLight()->boot();
        $mediator = $container->mediator();

        $events = $mediator->send(new CreateCourseCommand('new-course', 'New', 20));

        self::assertCount(1, $events);
        self::assertSame('new-course', $events[0]->courseId);
    }

    public function test_it_returns_course_not_found_during_check_before_course_creation(): void
    {
        $container = $this->ultraLight()->boot();
        $mediator = $container->mediator();

        try {
            $mediator->send(new SubscribeStudentToCourseCommand('missing-course', 'alice'));
            self::fail('Expected InvariantViolation to be thrown.');
        } catch (InvariantViolation $exception) {
            self::assertViolationCodes($exception->violations, 'course.not_found');
        }

        $violations = $mediator->send(
            new SubscribeStudentToCourseCommand('missing-course', 'alice'),
            ['decision_mode' => 'check'],
        );

        self::assertViolationCodes($violations, 'course.not_found');
    }

    public function test_it_allows_multiple_commands_in_sequence(): void
    {
        $container = $this->ultraLight()->boot();
        $mediator = $container->mediator();
        $store = $container->eventStore();

        $mediator->send(new CreateCourseCommand('math-101', 'Math', 10));
        $mediator->send(new SubscribeStudentToCourseCommand('math-101', 'alice'));
        $mediator->send(new SubscribeStudentToCourseCommand('math-101', 'bob'));

        self::assertCount(3, $store->load(self::courseStreamQuery('math-101'))->events);
    }

    public function test_it_returns_empty_violations_for_valid_check_requests(): void
    {
        $container = $this->ultraLight()->boot();
        $mediator = $container->mediator();

        $mediator->send(new CreateCourseCommand('math-101', 'Math', 10));

        $violations = $mediator->send(
            new SubscribeStudentToCourseCommand('math-101', 'alice'),
            ['decision_mode' => 'check'],
        );

        self::assertSame([], $violations);
    }

    public function test_it_returns_course_full_for_an_invalid_subscription_check(): void
    {
        $container = $this->ultraLight()->boot();
        $mediator = $container->mediator();

        $mediator->send(new CreateCourseCommand('math-101', 'Math', 1));
        $mediator->send(new SubscribeStudentToCourseCommand('math-101', 'alice'));

        $violations = $mediator->send(
            new SubscribeStudentToCourseCommand('math-101', 'bob'),
            ['decision_mode' => 'check'],
        );

        self::assertViolationCodes($violations, 'course.full');
    }

    public function test_it_returns_student_already_subscribed_when_a_student_subscribes_twice(): void
    {
        $container = $this->ultraLight()->boot();
        $mediator = $container->mediator();

        $mediator->send(new CreateCourseCommand('math-101', 'Math', 10));
        $mediator->send(new SubscribeStudentToCourseCommand('math-101', 'alice'));

        $violations = $mediator->send(
            new SubscribeStudentToCourseCommand('math-101', 'alice'),
            ['decision_mode' => 'check'],
        );

        self::assertViolationCodes($violations, 'student.already_subscribed');
    }

    public function test_check_mode_runs_the_decision_and_appends_nothing(): void
    {
        $container = $this->ultraLight()->boot();

        self::assertSame([], $container->mediator()->send(new CreateCourseCommand('x', 'X', 1), ['decision_mode' => 'check']));
        self::assertCount(0, $container->eventStore()->load(self::courseStreamQuery('x'))->events);
    }

    public function test_it_resolves_non_state_dependencies_from_the_container(): void
    {
        $command = new readonly class('timed-1', 'Timed Course', 10) {
            public function __construct(
                public string $courseId,
                public string $name,
                public int $maxCapacity,
            ) {}

            #[SelfHandler]
            #[DecisionHandler]
            public function decide(CourseCapacityState $course, \DateTimeInterface $clock): array
            {
                if ('2020-06-15 12:00:00' !== $clock->format('Y-m-d H:i:s')) {
                    throw new \RuntimeException('Clock dependency was not resolved from the container.');
                }

                if ($course->exists()) {
                    throw new \InvalidArgumentException('Course already exists');
                }

                return [new CourseCreated($this->courseId, $this->name, $this->maxCapacity)];
            }
        };

        $container = $this->ultraLight()
            ->withService(new \DateTimeImmutable('2020-06-15 12:00:00'), \DateTimeInterface::class)
            ->withClass($command::class)
            ->boot()
        ;
        $mediator = $container->mediator();
        $store = $container->eventStore();

        self::assertSame([], $mediator->send($command, ['decision_mode' => 'check']));

        $events = $mediator->send($command);

        self::assertEquals(
            [new CourseCreated('timed-1', 'Timed Course', 10)],
            $events,
        );
        self::assertCount(1, $store->load(self::courseStreamQuery('timed-1'))->events);
    }

    public function test_it_can_unsubscribe_a_student_and_update_the_streams(): void
    {
        $container = $this->ultraLight()->boot();
        $mediator = $container->mediator();
        $store = $container->eventStore();

        $mediator->send(new CreateCourseCommand('math-101', 'Math', 10));
        $mediator->send(new SubscribeStudentToCourseCommand('math-101', 'alice'));

        $events = $mediator->send(new UnsubscribeStudentFromCourseCommand('math-101', 'alice'));

        self::assertEquals([new StudentUnsubscribed('math-101', 'alice')], $events);
        self::assertCount(3, $store->load(self::courseStreamQuery('math-101'))->events);
        self::assertCount(2, $store->load(self::studentStreamQuery('alice'))->events);

        $violations = $mediator->send(
            new SubscribeStudentToCourseCommand('math-101', 'alice'),
            ['decision_mode' => 'check'],
        );

        self::assertSame([], $violations);
    }

    public function test_it_returns_missing_course_and_not_subscribed_violations_for_unsubscribe_checks(): void
    {
        $container = $this->ultraLight()->boot();
        $mediator = $container->mediator();

        $violations = $mediator->send(
            new UnsubscribeStudentFromCourseCommand('math-101', 'alice'),
            ['decision_mode' => 'check'],
        );

        self::assertViolationCodes($violations, 'course.not_found', 'student.not_subscribed');
    }

    public function test_it_routes_events_to_both_course_and_student_streams(): void
    {
        $container = $this->ultraLight()->boot();
        $mediator = $container->mediator();
        $store = $container->eventStore();

        $mediator->send(new CreateCourseCommand('math-101', 'Math', 10));
        $mediator->send(new SubscribeStudentToCourseCommand('math-101', 'alice'));

        $courseStream = $store->load(self::courseStreamQuery('math-101'));
        $studentStream = $store->load(self::studentStreamQuery('alice'));

        self::assertCount(2, $courseStream->events);
        self::assertCount(1, $studentStream->events);
        self::assertSame('student.subscribed', $studentStream->events[0]->eventType);
    }

    public function test_it_publishes_emitted_events_to_the_event_bus_when_available(): void
    {
        $eventBus = new class implements EventBusInterface {
            /** @var list<array{event: object, context: array<string, mixed>}> */
            public array $calls = [];

            public function publish(object $event, array|MessageContext $context = []): void
            {
                $this->calls[] = [
                    'event' => $event,
                    'context' => MessageContext::coerce($context)->all(),
                ];
            }
        };

        $container = $this->ultraLight()
            ->withService($eventBus, EventBusInterface::class)
            ->boot()
        ;
        $mediator = $container->mediator();

        $events = $mediator->send(new CreateCourseCommand('math-101', 'Math', 10), ['traceId' => 'trace-123']);

        self::assertCount(1, $events);
        self::assertCount(1, $eventBus->calls);
        self::assertEquals($events[0], $eventBus->calls[0]['event']);
        self::assertSame('trace-123', $eventBus->calls[0]['context']['traceId']);
    }

    public function test_it_stores_and_publishes_emitted_events_with_the_same_message_context_ids(): void
    {
        $eventBus = new class implements EventBusInterface {
            /** @var list<array{event: object, context: array<string, mixed>}> */
            public array $calls = [];

            public function publish(object $event, array|MessageContext $context = []): void
            {
                $this->calls[] = [
                    'event' => $event,
                    'context' => MessageContext::coerce($context)->all(),
                ];
            }
        };

        $container = $this->ultraLight()
            ->withService($eventBus, EventBusInterface::class)
            ->boot()
        ;

        $container->mediator()->send(new CreateCourseCommand('metadata-101', 'Metadata', 10), ['traceId' => 'trace-meta']);

        $storedEvents = $container->eventStore()->load(self::courseStreamQuery('metadata-101'))->events;

        self::assertCount(1, $storedEvents);
        self::assertCount(1, $eventBus->calls);
        self::assertSame(
            $storedEvents[0]->metadata[MessageContextKeys::MESSAGE_ID],
            $eventBus->calls[0]['context'][MessageContextKeys::MESSAGE_ID],
        );
        self::assertSame(
            $storedEvents[0]->metadata[MessageContextKeys::CORRELATION_ID],
            $eventBus->calls[0]['context'][MessageContextKeys::CORRELATION_ID],
        );
        self::assertSame(
            $storedEvents[0]->metadata[MessageContextKeys::CAUSATION_ID],
            $eventBus->calls[0]['context'][MessageContextKeys::CAUSATION_ID],
        );
        self::assertSame('trace-meta', $storedEvents[0]->metadata['traceId']);
    }

    public function test_it_publishes_emitted_events_with_the_final_context_from_inner_around_advices(): void
    {
        $eventBus = new class implements EventBusInterface {
            /** @var list<array{event: object, context: array<string, mixed>}> */
            public array $calls = [];

            public function publish(object $event, array|MessageContext $context = []): void
            {
                $this->calls[] = [
                    'event' => $event,
                    'context' => MessageContext::coerce($context)->all(),
                ];
            }
        };

        $aspect = new class {
            #[Around(priority: 10)]
            public function addPublishingContext(callable $proceed, object $message, MessageContext $context): ProceedResult
            {
                return $proceed(context: $context->with('publishedBy', 'inner-advice'));
            }
        };

        $container = $this->ultraLight()
            ->withService($eventBus, EventBusInterface::class)
            ->withService($aspect)
            ->boot()
        ;
        $mediator = $container->mediator();

        $events = $mediator->send(new CreateCourseCommand('history-101', 'History', 10), ['traceId' => 'trace-ctx']);

        self::assertCount(1, $events);
        self::assertCount(1, $eventBus->calls);
        self::assertEquals($events[0], $eventBus->calls[0]['event']);
        self::assertSame('trace-ctx', $eventBus->calls[0]['context']['traceId']);
        self::assertSame('inner-advice', $eventBus->calls[0]['context']['publishedBy']);
    }

    /**
     * @return UltraLight<StatefulMessageHandlingTestContainer>
     */
    private function ultraLight(): UltraLight
    {
        return $this->decisionUltraLight([
            CourseCreated::class,
            StudentSubscribed::class,
            StudentUnsubscribed::class,
        ])->withClass(
            CreateCourseCommand::class,
            SubscribeStudentToCourseCommand::class,
            UnsubscribeStudentFromCourseCommand::class,
            CourseCapacityState::class,
            StudentSubscriptionState::class,
            NestedCourseReference::class,
            CreateNestedCourseCommand::class,
            CreateCourseWithSeparateHandlerCommand::class,
            CreateCourseWithSeparateHandler::class,
        );
    }

    private static function courseStreamQuery(string $courseId): StreamQuery
    {
        return StreamQuery::forStream(new DomainIdentifier('course', $courseId))
            ->ofTypes('course.created', 'student.subscribed', 'student.unsubscribed')
        ;
    }

    private static function studentStreamQuery(string $studentId): StreamQuery
    {
        return StreamQuery::forStream(new DomainIdentifier('student', $studentId))
            ->ofTypes('student.subscribed', 'student.unsubscribed')
        ;
    }

    /**
     * @param list<Violation> $violations
     */
    private static function assertViolationCodes(array $violations, string ...$expectedCodes): void
    {
        self::assertSame(
            $expectedCodes,
            array_map(static fn (Violation $violation): string => $violation->code, $violations),
        );
    }
}
