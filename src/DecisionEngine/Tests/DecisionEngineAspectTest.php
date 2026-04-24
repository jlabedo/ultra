<?php

declare(strict_types=1);

namespace Ultra\DecisionEngine\Tests;

use Ultra\DecisionEngine\Attribute\DecisionHandler;
use Ultra\DecisionEngine\Exception\CommandHasNoCheckException;
use Ultra\DecisionEngine\Exception\InvariantViolation;
use Ultra\DecisionEngine\Tests\Example\Command\CreateCourseCommand;
use Ultra\DecisionEngine\Tests\Example\Command\SubscribeStudentToCourseCommand;
use Ultra\DecisionEngine\Tests\Example\Command\UnsubscribeStudentFromCourseCommand;
use Ultra\DecisionEngine\Tests\Example\Event\CourseCreated;
use Ultra\DecisionEngine\Tests\Example\Event\StudentSubscribed;
use Ultra\DecisionEngine\Tests\Example\Event\StudentUnsubscribed;
use Ultra\DecisionEngine\Tests\Example\State\CourseCapacityState;
use Ultra\DecisionEngine\Tests\Example\State\StudentSubscriptionState;
use Ultra\DecisionEngine\Tests\Support\DecisionEngineFunctionalTestCase;
use Ultra\DecisionEngine\Tests\Support\DecisionEngineTestContainer;
use Ultra\DecisionEngine\Violation;
use Ultra\EventStore\DomainIdentifier;
use Ultra\EventStore\StreamQuery;
use Ultra\MessageHandling\Attribute\Around;
use Ultra\MessageHandling\Attribute\SelfHandler;
use Ultra\MessageHandling\EventBusInterface;
use Ultra\MessageHandling\MessageContext;
use Ultra\MessageHandling\ProceedResult;
use Ultra\Testing\UltraLight;

/**
 * @internal
 *
 * @coversNothing
 */
final class DecisionEngineAspectTest extends DecisionEngineFunctionalTestCase
{
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

    public function test_it_throws_if_the_command_has_no_check_method(): void
    {
        $container = $this->ultraLight()->boot();
        $mediator = $container->mediator();

        $this->expectException(CommandHasNoCheckException::class);

        $mediator->send(new CreateCourseCommand('x', 'X', 1), ['decision_mode' => 'check']);
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
     * @return UltraLight<DecisionEngineTestContainer>
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
