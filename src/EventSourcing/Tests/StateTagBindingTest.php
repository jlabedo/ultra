<?php

declare(strict_types=1);

namespace Ultra\EventStore\Tests;

use PHPUnit\Framework\Attributes\CoversNamespace;
use PHPUnit\Framework\Attributes\DataProvider;
use Ultra\EventSourcedState\Exception\DuplicateTagValueException;
use Ultra\EventSourcedState\Exception\MissingTagValueException;
use Ultra\EventSourcedState\Exception\UnexpectedTagValueException;
use Ultra\EventSourcedState\Tests\Example\Event\CourseCapacityChanged;
use Ultra\EventSourcedState\Tests\Example\Event\CourseCreated;
use Ultra\EventSourcedState\Tests\Example\Event\StudentEnrolled;
use Ultra\EventSourcedState\Tests\Example\Event\StudentSubscribed;
use Ultra\EventSourcedState\Tests\Example\Event\StudentTransferred;
use Ultra\EventSourcedState\Tests\Example\Event\StudentUnsubscribed;
use Ultra\EventSourcedState\Tests\Example\State\CourseRosterState;
use Ultra\EventSourcedState\Tests\Example\State\CourseStudentActivityState;
use Ultra\EventSourcedState\Tests\Support\EventSourcedStateFunctionalTestCase;
use Ultra\EventStore\DomainIdentifier;
use Ultra\EventStore\StreamQuery;
use Ultra\EventStore\Tests\Support\CompilerAliasedRoomDayState;
use Ultra\EventStore\Tests\Support\CompilerRoomDayBookedEvent;
use Ultra\EventStore\Tests\Support\CompilerRoomDayMovedEvent;
use Ultra\EventStore\Tests\Support\CompilerSameDayMoveState;

require_once __DIR__ . '/Support/StateDeclarationFixtures.php';

/** @internal */
#[CoversNamespace('Ultra\EventSourcedState')]
#[CoversNamespace('Ultra\EventSourcedState_Compiler')]
final class StateTagBindingTest extends EventSourcedStateFunctionalTestCase
{
    /**
     * @param class-string $state
     * @param list<DomainIdentifier> $identifiers
     * @param class-string<\Throwable> $exception
     */
    #[DataProvider('invalidIdentifiers')]
    public function test_generated_loaders_require_each_entity_tag_once_and_reject_aliases_as_identity(
        string $state,
        array $identifiers,
        string $exception,
    ): void {
        $container = $this
            ->eventSourcedStateUltraLight([
                CourseCreated::class,
                CourseCapacityChanged::class,
                StudentEnrolled::class,
                StudentSubscribed::class,
                StudentUnsubscribed::class,
                StudentTransferred::class,
            ])
            ->withClass($state)
            ->boot();
        $this->expectException($exception);
        $container->eventSourcedStateStore()->load($state, ...$identifiers);
    }

    /** @return iterable<string, array{class-string, list<DomainIdentifier>, class-string<\Throwable>}> */
    public static function invalidIdentifiers(): iterable
    {
        $course = new DomainIdentifier('course', 'c1');
        $student = new DomainIdentifier('student', 's1');
        yield 'missing student' => [CourseStudentActivityState::class, [$course], MissingTagValueException::class];
        yield 'unexpected faculty' => [
            CourseStudentActivityState::class,
            [$course, $student, new DomainIdentifier('faculty', 'f1')],
            UnexpectedTagValueException::class,
        ];
        yield 'duplicate course' => [
            CourseStudentActivityState::class,
            [$course, new DomainIdentifier('course', 'c2'), $student],
            DuplicateTagValueException::class,
        ];
        yield 'alias is not identity' => [
            CourseRosterState::class,
            [new DomainIdentifier('previousCourse', 'c1')],
            UnexpectedTagValueException::class,
        ];
    }

    public function test_aliases_reuse_their_tag_value_in_independent_or_combined_match_groups(): void
    {
        $container = $this
            ->eventSourcedStateUltraLight([CompilerRoomDayBookedEvent::class, CompilerRoomDayMovedEvent::class])
            ->withClass(CompilerAliasedRoomDayState::class, CompilerSameDayMoveState::class)
            ->boot();
        $store = $container->eventSourcedStateStore();
        $identifier = new DomainIdentifier('roomDay', 'r1@2026-09-28');

        static::assertEquals(
            StreamQuery::anyOf(
                StreamQuery::forStream(new DomainIdentifier('previousRoomDay', $identifier->value))->ofTypes(
                    'compiler.room_day_moved',
                ),
                StreamQuery::forStream($identifier)->ofTypes('compiler.room_day_booked', 'compiler.room_day_moved'),
            ),
            $store->loader(CompilerAliasedRoomDayState::class)->query($identifier),
        );
        static::assertEquals(
            StreamQuery::forStream($identifier, new DomainIdentifier('previousRoomDay', $identifier->value))->ofTypes(
                'compiler.room_day_moved',
            ),
            $store->loader(CompilerSameDayMoveState::class)->query($identifier),
        );
    }
}
