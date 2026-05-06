<?php

declare(strict_types=1);

namespace Ultra\DecisionEngine\Tests;

use Ultra\EventSourcedState\EventSourcedStateStore;
use Ultra\EventSourcedState\EventSourcedEntityLoader;
use Ultra\EventSourcedState\Exception\DuplicateTagValueException;
use Ultra\EventSourcedState\Exception\MissingTagValueException;
use Ultra\EventSourcedState\Exception\UnexpectedTagValueException;
use Ultra\EventSourcedState\Exception\UnknownEventSourcedEntityException;
use Ultra\DecisionEngine\Tests\Example\Event\CourseCapacityChanged;
use Ultra\DecisionEngine\Tests\Example\Event\CourseCreated;
use Ultra\DecisionEngine\Tests\Example\Event\StudentEnrolled;
use Ultra\DecisionEngine\Tests\Example\Event\StudentSubscribed;
use Ultra\DecisionEngine\Tests\Example\Event\StudentUnsubscribed;
use Ultra\DecisionEngine\Tests\Example\Event\SubscriptionPairConfirmed;
use Ultra\DecisionEngine\Tests\Example\State\CourseStudentActivityState;
use Ultra\DecisionEngine\Tests\Example\State\SubscriptionPairStatusState;
use Ultra\DecisionEngine\Tests\Support\DecisionEngineFunctionalTestCase;
use Ultra\DecisionEngine\Tests\Support\DecisionEngineTestContainer;
use Ultra\EventStore\DomainIdentifier as StreamIdentifier;
use Ultra\EventStore\Event as StoredEvent;
use Ultra\EventStore\StreamQuery;
use Ultra\Testing\UltraLight;

/**
 * @internal
 *
 * @coversNothing
 */
final class EventSourcedStateStoreTest extends DecisionEngineFunctionalTestCase
{
    public function test_it_loads_entities_across_single_tag_and_pair_events(): void
    {
        $container = $this->ultraLight()
            ->withClass(
                CourseStudentActivityState::class,
                SubscriptionPairStatusState::class,
            )
            ->boot()
        ;

        $container->eventStore()->append([
            new StoredEvent('course.created', ['courseId' => 'course-1', 'name' => 'Math', 'maxCapacity' => 10], ['course' => 'course-1']),
            new StoredEvent('student.enrolled', ['studentId' => 'student-1'], ['student' => 'student-1']),
            new StoredEvent('student.subscribed', ['courseId' => 'course-1', 'studentId' => 'student-1'], ['course' => 'course-1', 'student' => 'student-1']),
            new StoredEvent('course.capacity_changed', ['courseId' => 'course-1', 'maxCapacity' => 25], ['course' => 'course-1']),
            new StoredEvent('student.unsubscribed', ['courseId' => 'course-1', 'studentId' => 'student-1'], ['course' => 'course-1', 'student' => 'student-1']),
        ]);

        $stateStore = $container->eventSourcedStateStore();
        $state = $stateStore->load(
            CourseStudentActivityState::class,
            new StreamIdentifier('course', 'course-1'),
            new StreamIdentifier('student', 'student-1'),
        );

        self::assertInstanceOf(CourseStudentActivityState::class, $state);
        self::assertSame(25, $state->maxCapacity);
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

    public function test_it_requires_all_tags_in_a_multi_tag_match_group(): void
    {
        $container = $this->ultraLight()
            ->withClass(SubscriptionPairStatusState::class)
            ->boot()
        ;

        $container->eventStore()->append([
            new StoredEvent('subscription.pair_confirmed', ['courseId' => 'course-1', 'studentId' => 'student-1', 'status' => 'matched'], ['course' => 'course-1', 'student' => 'student-1']),
            new StoredEvent('subscription.pair_confirmed', ['courseId' => 'course-1', 'studentId' => 'student-2', 'status' => 'other-student'], ['course' => 'course-1', 'student' => 'student-2']),
        ]);

        $stateStore = $container->eventSourcedStateStore();
        $state = $stateStore->load(
            SubscriptionPairStatusState::class,
            new StreamIdentifier('course', 'course-1'),
            new StreamIdentifier('student', 'student-1'),
        );

        self::assertSame(['matched'], $state->statuses);
    }

    public function test_it_validates_missing_unexpected_and_duplicate_tag_values_through_generated_loaders(): void
    {
        $container = $this->ultraLight()
            ->withClass(CourseStudentActivityState::class)
            ->boot()
        ;
        $stateStore = $container->eventSourcedStateStore();

        try {
            $stateStore->load(
                CourseStudentActivityState::class,
                new StreamIdentifier('course', 'course-1'),
            );
            self::fail('Expected MissingTagValueException to be thrown.');
        } catch (MissingTagValueException) {
        }

        try {
            $stateStore->load(
                CourseStudentActivityState::class,
                new StreamIdentifier('course', 'course-1'),
                new StreamIdentifier('student', 'student-1'),
                new StreamIdentifier('faculty', 'faculty-1'),
            );
            self::fail('Expected UnexpectedTagValueException to be thrown.');
        } catch (UnexpectedTagValueException) {
        }

        $this->expectException(DuplicateTagValueException::class);

        $stateStore->load(
            CourseStudentActivityState::class,
            new StreamIdentifier('course', 'course-1'),
            new StreamIdentifier('course', 'course-1b'),
            new StreamIdentifier('student', 'student-1'),
        );
    }

    public function test_it_requires_an_event_store_to_load_entities(): void
    {
        $loader = new class implements EventSourcedEntityLoader {
            public function query(StreamIdentifier ...$identifiers): StreamQuery
            {
                return StreamQuery::forStream(new StreamIdentifier('course', 'course-1'));
            }

            public function hydrate(array $events, StreamIdentifier ...$identifiers): object
            {
                return new \stdClass();
            }
        };

        $stateStore = new EventSourcedStateStore([
            CourseStudentActivityState::class => $loader,
        ]);

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('EventSourcedStateStore cannot load '.CourseStudentActivityState::class.' because no event store was configured.');

        $stateStore->load(
            CourseStudentActivityState::class,
            new StreamIdentifier('course', 'course-1'),
            new StreamIdentifier('student', 'student-1'),
        );
    }

    public function test_it_throws_for_unknown_event_sourced_entities(): void
    {
        $container = $this->ultraLight()
            ->withClass(CourseStudentActivityState::class)
            ->boot()
        ;
        $stateStore = $container->eventSourcedStateStore();

        $unknownStateClass = new class {};

        $this->expectException(UnknownEventSourcedEntityException::class);

        $stateStore->load($unknownStateClass::class);
    }

    /**
     * @return UltraLight<DecisionEngineTestContainer>
     */
    private function ultraLight(): UltraLight
    {
        return $this->decisionUltraLight(self::eventClasses());
    }

    /**
     * @return list<class-string>
     */
    private static function eventClasses(): array
    {
        return [
            CourseCreated::class,
            CourseCapacityChanged::class,
            StudentEnrolled::class,
            StudentSubscribed::class,
            StudentUnsubscribed::class,
            SubscriptionPairConfirmed::class,
        ];
    }
}
