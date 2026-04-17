<?php

declare(strict_types=1);

namespace Ultra\EventStore\Tests;

use PHPUnit\Framework\Attributes\CoversClass;
use Ultra\EventStore\AppendCondition;
use Ultra\EventStore\Checkpoint;
use Ultra\EventStore\DomainIdentifier;
use Ultra\EventStore\Sequence;
use Ultra\EventStore\StreamQuery;
use Ultra\EventStore\Tests\Support\EventStoreTestCase;

/**
 * @internal
 */
#[CoversClass(AppendCondition::class)]
final class AppendConditionTest extends EventStoreTestCase
{
    public function test_append_condition_carries_a_checkpoint_and_the_loaded_query(): void
    {
        $checkpoint = Checkpoint::at(new Sequence(42));
        $query = StreamQuery::anyOf(
            StreamQuery::forStream(
                new DomainIdentifier('courseId', 'course-123'),
                new DomainIdentifier('studentId', 'student-1'),
            )->ofTypes('student.subscribed'),
            StreamQuery::forStream(
                new DomainIdentifier('courseId', 'course-123'),
                new DomainIdentifier('studentId', 'student-2'),
            )->ofTypes('student.subscribed'),
        );

        $appendCondition = new AppendCondition($checkpoint, $query);

        self::assertSame($checkpoint, $appendCondition->checkpoint);
        self::assertSame($query, $appendCondition->query);
    }

    public function test_append_condition_rejects_global_queries(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Append conditions cannot use global stream queries.');

        new AppendCondition(Checkpoint::at(new Sequence(42)), StreamQuery::all());
    }

    public function test_append_condition_rejects_typed_global_queries(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Append conditions cannot use global stream queries.');

        new AppendCondition(
            Checkpoint::at(new Sequence(42)),
            StreamQuery::all()->ofTypes('course.created'),
        );
    }
}
