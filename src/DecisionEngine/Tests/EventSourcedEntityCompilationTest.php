<?php

declare(strict_types=1);

namespace Ultra\DecisionEngine\Tests;

use Symfony\Component\DependencyInjection\Container;
use Ultra\DecisionEngine\Attribute\EventSourcedEntity;
use Ultra\DecisionEngine\Attribute\EventSourcingHandler;
use Ultra\DecisionEngine\Attribute\MatchTag;
use Ultra\DecisionEngine\Engine;
use Ultra\DecisionEngine\Exception\AmbiguousTagError;
use Ultra\DecisionEngine\Exception\DuplicateEventSourcingHandlerError;
use Ultra\DecisionEngine\Exception\DuplicateTagValueException;
use Ultra\DecisionEngine\Exception\InvalidMatchTagError;
use Ultra\DecisionEngine\Exception\MissingEventSourcingHandlerError;
use Ultra\DecisionEngine\Exception\MissingTagValueException;
use Ultra\DecisionEngine\Exception\MissingTypeHintError;
use Ultra\DecisionEngine\Exception\NoTagOverlapError;
use Ultra\DecisionEngine\Exception\UnexpectedTagValueException;
use Ultra\DecisionEngine\Exception\UnknownEventSourcedEntityException;
use Ultra\DecisionEngine\Exception\UnnecessaryMatchTagError;
use Ultra\DecisionEngine\Tests\Support\DecisionEngineFunctionalTestCase;
use Ultra\Events\Attribute\DomainIdentifier;
use Ultra\Events\Attribute\Event;
use Ultra\EventStore\DomainIdentifier as StreamIdentifier;
use Ultra\EventStore\Event as StoredEvent;

#[Event('compiler.course_created')]
final readonly class CompilerCourseCreated
{
    public function __construct(
        #[DomainIdentifier('course')]
        public string $courseId,
        public int $capacity,
    ) {}
}

#[Event('compiler.course_capacity_changed')]
final readonly class CompilerCourseCapacityChanged
{
    public function __construct(
        #[DomainIdentifier('course')]
        public string $courseId,
        public int $capacity,
    ) {}
}

#[Event('compiler.student_enrolled')]
final readonly class CompilerStudentEnrolled
{
    public function __construct(
        #[DomainIdentifier('student')]
        public string $studentId,
    ) {}
}

#[Event('compiler.student_subscribed')]
final readonly class CompilerStudentSubscribed
{
    public function __construct(
        #[DomainIdentifier('course')]
        public string $courseId,
        #[DomainIdentifier('student')]
        public string $studentId,
    ) {}
}

#[Event('compiler.student_unsubscribed')]
final readonly class CompilerStudentUnsubscribed
{
    public function __construct(
        #[DomainIdentifier('course')]
        public string $courseId,
        #[DomainIdentifier('student')]
        public string $studentId,
    ) {}
}

#[Event('compiler.pair_confirmed')]
final readonly class CompilerPairConfirmed
{
    public function __construct(
        #[DomainIdentifier('course')]
        public string $courseId,
        #[DomainIdentifier('student')]
        public string $studentId,
        public string $status,
    ) {}
}

#[EventSourcedEntity(tags: ['course', 'student'])]
final class CompilerSubscriptionState
{
    public int $capacity = 0;
    public bool $studentKnown = false;
    public int $subscriptionDelta = 0;

    /** @var list<string> */
    public array $applied = [];

    #[EventSourcingHandler]
    public function onCourseCreated(CompilerCourseCreated $event): void
    {
        $this->capacity = $event->capacity;
        $this->applied[] = 'created';
    }

    #[EventSourcingHandler]
    public function onCourseCapacityChanged(CompilerCourseCapacityChanged $event): void
    {
        $this->capacity = $event->capacity;
        $this->applied[] = 'capacity_changed';
    }

    #[EventSourcingHandler]
    public function onStudentEnrolled(CompilerStudentEnrolled $event): void
    {
        $this->studentKnown = true;
        $this->applied[] = 'student_enrolled';
    }

    #[EventSourcingHandler]
    #[MatchTag('course')]
    #[MatchTag('student')]
    public function onStudentSubscribed(CompilerStudentSubscribed $event): void
    {
        ++$this->subscriptionDelta;
        $this->applied[] = 'student_subscribed';
    }

    #[EventSourcingHandler]
    #[MatchTag('course')]
    #[MatchTag('student')]
    public function onStudentUnsubscribed(CompilerStudentUnsubscribed $event): void
    {
        --$this->subscriptionDelta;
        $this->applied[] = 'student_unsubscribed';
    }
}

#[EventSourcedEntity(tags: ['course', 'student'])]
final class CompilerExactSubscriptionState
{
    /** @var list<string> */
    public array $statuses = [];

    #[EventSourcingHandler]
    #[MatchTag('course', 'student')]
    public function onPairConfirmed(CompilerPairConfirmed $event): void
    {
        $this->statuses[] = $event->status;
    }
}

#[Event('compiler.no_overlap')]
final readonly class CompilerNoOverlapEvent
{
    public function __construct(
        #[DomainIdentifier('faculty')]
        public string $facultyId,
    ) {}
}

#[Event('compiler.ambiguous')]
final readonly class CompilerAmbiguousEvent
{
    public function __construct(
        #[DomainIdentifier('course')]
        public string $courseId,
        #[DomainIdentifier('student')]
        public string $studentId,
    ) {}
}

#[Event('compiler.single')]
final readonly class CompilerSingleTagEvent
{
    public function __construct(
        #[DomainIdentifier('course')]
        public string $courseId,
    ) {}
}

#[EventSourcedEntity(tags: ['course', 'student'])]
final class CompilerNoOverlapState
{
    #[EventSourcingHandler]
    public function onEvent(CompilerNoOverlapEvent $event): void {}
}

#[EventSourcedEntity(tags: ['course', 'student'])]
final class CompilerAmbiguousState
{
    #[EventSourcingHandler]
    public function onEvent(CompilerAmbiguousEvent $event): void {}
}

#[EventSourcedEntity(tags: ['course', 'student'])]
final class CompilerUnnecessaryMatchTagState
{
    #[EventSourcingHandler]
    #[MatchTag('course')]
    public function onEvent(CompilerSingleTagEvent $event): void {}
}

#[EventSourcedEntity(tags: ['course', 'student'])]
final class CompilerInvalidMatchTagState
{
    #[EventSourcingHandler]
    #[MatchTag('faculty')]
    public function onEvent(CompilerAmbiguousEvent $event): void {}
}

#[EventSourcedEntity(tags: ['course'])]
final class CompilerMissingHandlerAttributeState
{
    #[MatchTag('course')]
    public function onEvent(CompilerSingleTagEvent $event): void {}
}

#[EventSourcedEntity(tags: ['course'])]
final class CompilerMissingTypeHintState
{
    #[EventSourcingHandler]
    public function onEvent(string $event): void {}
}

#[EventSourcedEntity(tags: ['course'])]
final class CompilerDuplicateHandlerState
{
    #[EventSourcingHandler]
    public function onFirst(CompilerSingleTagEvent $event): void {}

    #[EventSourcingHandler]
    public function onSecond(CompilerSingleTagEvent $event): void {}
}

final class CompilerUnknownState {}

/**
 * @internal
 *
 * @coversNothing
 */
final class EventSourcedEntityCompilationTest extends DecisionEngineFunctionalTestCase
{
    public function test_engine_loads_entities_across_single_tag_and_pair_events(): void
    {
        $container = $this->bootCompilerContainer(
            CompilerSubscriptionState::class,
            CompilerExactSubscriptionState::class,
        );

        $container->get(\Ultra\EventStore\InMemoryEventStore::class)->append([
            new StoredEvent('compiler.course_created', ['courseId' => 'course-1', 'capacity' => 10], ['course' => 'course-1']),
            new StoredEvent('compiler.student_enrolled', ['studentId' => 'student-1'], ['student' => 'student-1']),
            new StoredEvent('compiler.student_subscribed', ['courseId' => 'course-1', 'studentId' => 'student-1'], ['course' => 'course-1', 'student' => 'student-1']),
            new StoredEvent('compiler.course_capacity_changed', ['courseId' => 'course-1', 'capacity' => 25], ['course' => 'course-1']),
            new StoredEvent('compiler.student_unsubscribed', ['courseId' => 'course-1', 'studentId' => 'student-1'], ['course' => 'course-1', 'student' => 'student-1']),
        ]);

        $engine = $container->get(Engine::class);
        $state = $engine->load(
            CompilerSubscriptionState::class,
            new StreamIdentifier('course', 'course-1'),
            new StreamIdentifier('student', 'student-1'),
        );

        self::assertInstanceOf(CompilerSubscriptionState::class, $state);
        self::assertSame(25, $state->capacity);
        self::assertTrue($state->studentKnown);
        self::assertSame(0, $state->subscriptionDelta);
        self::assertSame([
            'created',
            'student_enrolled',
            'student_subscribed',
            'capacity_changed',
            'student_unsubscribed',
        ], $state->applied);
    }

    public function test_engine_requires_all_tags_in_a_multi_tag_match_group(): void
    {
        $container = $this->bootCompilerContainer(CompilerExactSubscriptionState::class);

        $container->get(\Ultra\EventStore\InMemoryEventStore::class)->append([
            new StoredEvent('compiler.pair_confirmed', ['courseId' => 'course-1', 'studentId' => 'student-1', 'status' => 'matched'], ['course' => 'course-1', 'student' => 'student-1']),
            new StoredEvent('compiler.pair_confirmed', ['courseId' => 'course-1', 'studentId' => 'student-2', 'status' => 'other-student'], ['course' => 'course-1', 'student' => 'student-2']),
        ]);

        $engine = $container->get(Engine::class);
        $state = $engine->load(
            CompilerExactSubscriptionState::class,
            new StreamIdentifier('course', 'course-1'),
            new StreamIdentifier('student', 'student-1'),
        );

        self::assertSame(['matched'], $state->statuses);
    }

    public function test_engine_loader_validates_missing_unexpected_and_duplicate_tag_values(): void
    {
        $container = $this->bootCompilerContainer(CompilerSubscriptionState::class);
        $engine = $container->get(Engine::class);

        try {
            $engine->load(
                CompilerSubscriptionState::class,
                new StreamIdentifier('course', 'course-1'),
            );
            self::fail('Expected MissingTagValueException to be thrown.');
        } catch (MissingTagValueException) {
        }

        try {
            $engine->load(
                CompilerSubscriptionState::class,
                new StreamIdentifier('course', 'course-1'),
                new StreamIdentifier('student', 'student-1'),
                new StreamIdentifier('faculty', 'faculty-1'),
            );
            self::fail('Expected UnexpectedTagValueException to be thrown.');
        } catch (UnexpectedTagValueException) {
        }

        $this->expectException(DuplicateTagValueException::class);

        $engine->load(
            CompilerSubscriptionState::class,
            new StreamIdentifier('course', 'course-1'),
            new StreamIdentifier('course', 'course-1b'),
            new StreamIdentifier('student', 'student-1'),
        );
    }

    public function test_engine_throws_for_unknown_event_sourced_entities(): void
    {
        $container = $this->bootCompilerContainer(CompilerSubscriptionState::class);
        $engine = $container->get(Engine::class);

        $this->expectException(UnknownEventSourcedEntityException::class);

        $engine->load(CompilerUnknownState::class);
    }

    public function test_container_compilation_rejects_handlers_whose_events_share_no_tags_with_the_entity(): void
    {
        $this->expectException(NoTagOverlapError::class);

        $this->bootCompilerContainer(CompilerNoOverlapState::class);
    }

    public function test_container_compilation_rejects_ambiguous_handlers_without_match_tags(): void
    {
        $this->expectException(AmbiguousTagError::class);

        $this->bootCompilerContainer(CompilerAmbiguousState::class);
    }

    public function test_container_compilation_rejects_unnecessary_match_tags_for_single_tag_handlers(): void
    {
        $this->expectException(UnnecessaryMatchTagError::class);

        $this->bootCompilerContainer(CompilerUnnecessaryMatchTagState::class);
    }

    public function test_container_compilation_rejects_match_tags_outside_the_entity_and_event_intersection(): void
    {
        $this->expectException(InvalidMatchTagError::class);

        $this->bootCompilerContainer(CompilerInvalidMatchTagState::class);
    }

    public function test_container_compilation_rejects_match_tags_on_methods_that_are_not_handlers(): void
    {
        $this->expectException(MissingEventSourcingHandlerError::class);

        $this->bootCompilerContainer(CompilerMissingHandlerAttributeState::class);
    }

    public function test_container_compilation_rejects_handlers_without_a_class_typed_event_parameter(): void
    {
        $this->expectException(MissingTypeHintError::class);

        $this->bootCompilerContainer(CompilerMissingTypeHintState::class);
    }

    public function test_container_compilation_rejects_duplicate_handlers_for_the_same_event_type(): void
    {
        $this->expectException(DuplicateEventSourcingHandlerError::class);

        $this->bootCompilerContainer(CompilerDuplicateHandlerState::class);
    }

    /**
     * @param class-string ...$entityClasses
     */
    private function bootCompilerContainer(string ...$entityClasses): Container
    {
        return $this->decisionUltraLight(self::eventClasses())
            ->withClass(...$entityClasses)
            ->boot();
    }

    /**
     * @return list<class-string>
     */
    private static function eventClasses(): array
    {
        return [
            CompilerCourseCreated::class,
            CompilerCourseCapacityChanged::class,
            CompilerStudentEnrolled::class,
            CompilerStudentSubscribed::class,
            CompilerStudentUnsubscribed::class,
            CompilerPairConfirmed::class,
            CompilerNoOverlapEvent::class,
            CompilerAmbiguousEvent::class,
            CompilerSingleTagEvent::class,
        ];
    }
}
