<?php

declare(strict_types=1);

namespace Ultra\EventStore\Tests;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Ultra\EventStore\AppendCondition;
use Ultra\EventStore\Checkpoint;
use Ultra\EventStore\DomainIdentifier;
use Ultra\EventStore\Event;
use Ultra\EventStore\LoadResult;
use Ultra\EventStore\Sequence;
use Ultra\EventStore\StreamQuery;

/**
 * @internal
 */
#[CoversClass(LoadResult::class)]
final class LoadResultTest extends TestCase
{
    public function test_append_condition_uses_the_result_checkpoint_and_base_query(): void
    {
        $query = StreamQuery::forStream(
            new DomainIdentifier('courseId', 'course-123'),
        )->ofTypes('course.created');
        $checkpoint = Checkpoint::at(new Sequence(42));
        $event = new Event(
            eventType: 'course.created',
            payload: ['name' => 'Math 101'],
            domainIds: ['courseId' => 'course-123'],
        );

        $result = new LoadResult([$event], $checkpoint, $query);
        $appendCondition = $result->appendCondition();

        self::assertSame([$event], $result->events);
        self::assertSame($checkpoint, $result->checkpoint);
        self::assertSame($query, $result->baseQuery);
        self::assertInstanceOf(AppendCondition::class, $appendCondition);
        self::assertSame($checkpoint, $appendCondition->checkpoint);
        self::assertSame($query, $appendCondition->query);
    }

    public function test_append_condition_rejects_global_base_query(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Append conditions cannot use global stream queries.');

        $result = new LoadResult([], Checkpoint::at(new Sequence(42)), StreamQuery::all());

        $result->appendCondition();
    }
}
