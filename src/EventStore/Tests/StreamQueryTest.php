<?php

declare(strict_types=1);

namespace Ultra\EventStore\Tests;

use PHPUnit\Framework\Attributes\CoversClass;
use Ultra\EventStore\DomainIdentifier;
use Ultra\EventStore\StreamQuery;
use Ultra\EventStore\StreamQueryBranch;
use Ultra\EventStore\Tests\Support\EventStoreTestCase;

/**
 * @internal
 */
#[CoversClass(StreamQuery::class)]
#[CoversClass(StreamQueryBranch::class)]
final class StreamQueryTest extends EventStoreTestCase
{
    public function test_empty_query_matches_no_event(): void
    {
        $query = StreamQuery::none();
        $event = self::event(domainIds: ['course' => 'math-101']);

        self::assertTrue($query->isEmpty());
        self::assertFalse($query->matches($event));
    }

    public function test_constructor_discards_empty_branches(): void
    {
        $query = new StreamQuery([
            new StreamQueryBranch([]),
            new StreamQueryBranch(['course' => 'math-101']),
        ]);

        self::assertCount(1, $query->branches);
        self::assertTrue($query->matches(self::event(domainIds: ['course' => 'math-101'])));
    }

    public function test_for_stream_without_identifiers_builds_empty_query(): void
    {
        $query = StreamQuery::forStream();

        self::assertTrue($query->isEmpty());
        self::assertFalse($query->matches(self::event(domainIds: ['course' => 'math-101'])));
    }

    public function test_for_stream_with_one_identifier_matches_event_with_that_dimension_and_value(): void
    {
        $query = StreamQuery::forStream(new DomainIdentifier('course', 'math-101'));

        self::assertTrue($query->matches(self::event(domainIds: ['course' => 'math-101'])));
        self::assertFalse($query->matches(self::event(domainIds: ['course' => 'other-101'])));
        self::assertFalse($query->matches(self::event(domainIds: ['student' => 'alice'])));
    }

    public function test_for_stream_with_multiple_identifiers_matches_events_with_all_identifiers(): void
    {
        $query = StreamQuery::forStream(
            new DomainIdentifier('course', 'math-101'),
            new DomainIdentifier('student', 'alice'),
        );

        self::assertTrue($query->matches(self::event(domainIds: [
            'course' => 'math-101',
            'student' => 'alice',
        ])));
        self::assertFalse($query->matches(self::event(domainIds: [
            'course' => 'math-101',
        ])));
        self::assertFalse($query->matches(self::event(domainIds: [
            'course' => 'math-101',
            'student' => 'bob',
        ])));
    }

    public function test_any_of_matches_event_that_matches_any_branch(): void
    {
        $query = StreamQuery::anyOf(
            StreamQuery::forStream(new DomainIdentifier('course', 'math-101')),
            StreamQuery::forStream(new DomainIdentifier('student', 'alice')),
        );

        self::assertTrue($query->matches(self::event(domainIds: ['course' => 'math-101'])));
        self::assertTrue($query->matches(self::event(domainIds: ['student' => 'alice'])));
        self::assertFalse($query->matches(self::event(domainIds: ['other' => 'x'])));
    }

    public function test_any_of_flattens_nested_queries(): void
    {
        $query = StreamQuery::anyOf(
            StreamQuery::forStream(new DomainIdentifier('course', 'math-101')),
            StreamQuery::anyOf(
                StreamQuery::forStream(new DomainIdentifier('course', 'math-102')),
                StreamQuery::forStream(new DomainIdentifier('course', 'math-103')),
            ),
        );

        self::assertCount(3, $query->branches);
        self::assertTrue($query->matches(self::event(domainIds: ['course' => 'math-101'])));
        self::assertTrue($query->matches(self::event(domainIds: ['course' => 'math-102'])));
        self::assertTrue($query->matches(self::event(domainIds: ['course' => 'math-103'])));
    }

    public function test_of_types_filters_by_event_type(): void
    {
        $query = StreamQuery::forStream(new DomainIdentifier('course', 'math-101'))
            ->ofTypes('course.created', 'course.updated')
        ;

        self::assertTrue($query->matches(self::event(
            eventType: 'course.created',
            domainIds: ['course' => 'math-101'],
        )));
        self::assertFalse($query->matches(self::event(
            eventType: 'course.deleted',
            domainIds: ['course' => 'math-101'],
        )));
    }

    public function test_of_types_normalizes_named_variadic_event_names_to_lists(): void
    {
        $query = StreamQuery::forStream(new DomainIdentifier('course', 'math-101'))
            ->ofTypes(created: 'course.created', updated: 'course.updated')
        ;

        self::assertSame(['course.created', 'course.updated'], $query->branches[0]->eventTypes);
    }

    public function test_of_types_applies_to_each_existing_branch(): void
    {
        $query = StreamQuery::anyOf(
            StreamQuery::forStream(new DomainIdentifier('course', 'math-101')),
            StreamQuery::forStream(new DomainIdentifier('student', 'alice')),
        )->ofTypes('course.created');

        self::assertSame(['course.created'], $query->branches[0]->eventTypes);
        self::assertSame(['course.created'], $query->branches[1]->eventTypes);
    }

    public function test_branch_specific_event_types_are_preserved_when_combining_queries(): void
    {
        $query = StreamQuery::anyOf(
            StreamQuery::forStream(new DomainIdentifier('course', 'math-101'))
                ->ofTypes('course.created'),
            StreamQuery::forStream(new DomainIdentifier('course', 'math-102'))
                ->ofTypes('course.updated'),
        );

        self::assertTrue($query->matches(self::event(
            eventType: 'course.created',
            domainIds: ['course' => 'math-101'],
        )));
        self::assertTrue($query->matches(self::event(
            eventType: 'course.updated',
            domainIds: ['course' => 'math-102'],
        )));
        self::assertFalse($query->matches(self::event(
            eventType: 'course.created',
            domainIds: ['course' => 'math-102'],
        )));
        self::assertFalse($query->matches(self::event(
            eventType: 'course.updated',
            domainIds: ['course' => 'math-101'],
        )));
    }

    public function test_empty_event_type_filter_means_all_event_types_match(): void
    {
        $query = StreamQuery::forStream(new DomainIdentifier('course', 'math-101'));

        self::assertTrue($query->matches(self::event(
            eventType: 'any.event.type',
            domainIds: ['course' => 'math-101'],
        )));
    }
}
