<?php

declare(strict_types=1);

namespace Ultra\DecisionEngine\Tests;

use Symfony\Component\DependencyInjection\Container;
use Ultra\DecisionEngine\Engine;
use Ultra\DecisionEngine\Exception\DuplicateTagValueException;
use Ultra\DecisionEngine\Exception\MissingTagValueException;
use Ultra\DecisionEngine\Exception\UnexpectedTagValueException;
use Ultra\DecisionEngine\Exception\UnknownEventSourcedEntityException;
use Ultra\DecisionEngine\Tests\Support\CompilerCourseCapacityChanged;
use Ultra\DecisionEngine\Tests\Support\CompilerCourseCreated;
use Ultra\DecisionEngine\Tests\Support\CompilerExactSubscriptionState;
use Ultra\DecisionEngine\Tests\Support\CompilerPairConfirmed;
use Ultra\DecisionEngine\Tests\Support\CompilerStudentEnrolled;
use Ultra\DecisionEngine\Tests\Support\CompilerStudentSubscribed;
use Ultra\DecisionEngine\Tests\Support\CompilerStudentUnsubscribed;
use Ultra\DecisionEngine\Tests\Support\CompilerSubscriptionState;
use Ultra\DecisionEngine\Tests\Support\CompilerUnknownState;
use Ultra\DecisionEngine\Tests\Support\DecisionEngineFunctionalTestCase;
use Ultra\EventStore\DomainIdentifier as StreamIdentifier;
use Ultra\EventStore\Event as StoredEvent;

require_once __DIR__.'/Support/EventSourcedEntityFixtures.php';

/**
 * @internal
 *
 * @coversNothing
 */
final class EngineTest extends DecisionEngineFunctionalTestCase
{
    public function test_it_loads_entities_across_single_tag_and_pair_events(): void
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

    public function test_it_requires_all_tags_in_a_multi_tag_match_group(): void
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

    public function test_it_validates_missing_unexpected_and_duplicate_tag_values_through_generated_loaders(): void
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

    public function test_it_throws_for_unknown_event_sourced_entities(): void
    {
        $container = $this->bootCompilerContainer(CompilerSubscriptionState::class);
        $engine = $container->get(Engine::class);

        $this->expectException(UnknownEventSourcedEntityException::class);

        $engine->load(CompilerUnknownState::class);
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
        ];
    }
}
