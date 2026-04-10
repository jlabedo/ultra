<?php

declare(strict_types=1);

namespace Ultra\EventStore\Tests;

use PHPUnit\Framework\Attributes\CoversClass;
use Ultra\EventStore\DomainIdentifier;
use Ultra\EventStore\StreamQuery;
use Ultra\EventStore\Tests\Support\EventStoreTestCase;

/**
 * @internal
 */
#[CoversClass(StreamQuery::class)]
final class StreamQueryTest extends EventStoreTestCase
{
    public function test_matches_empty_query_matches_no_event(): void
    {
        $query = new StreamQuery([], [], []);
        $event = self::event(domainIds: ['course' => 'math-101']);

        self::assertFalse($query->matches($event));
    }

    public function test_for_stream_without_identifiers_builds_empty_query(): void
    {
        $query = StreamQuery::forStream();

        self::assertTrue($query->isEmpty());
        self::assertFalse($query->matches(self::event(domainIds: ['course' => 'math-101'])));
    }

    public function test_matches_for_stream_with_one_identifier_matches_event_with_that_dimension_and_value(): void
    {
        $query = StreamQuery::forStream(new DomainIdentifier('course', 'math-101'));
        $event = self::event(domainIds: ['course' => 'math-101']);

        self::assertTrue($query->matches($event));
    }

    public function test_matches_for_stream_does_not_match_when_dimension_value_differs(): void
    {
        $query = StreamQuery::forStream(new DomainIdentifier('course', 'math-101'));
        $event = self::event(domainIds: ['course' => 'other-101']);

        self::assertFalse($query->matches($event));
    }

    public function test_matches_for_stream_does_not_match_when_dimension_is_missing_on_event(): void
    {
        $query = StreamQuery::forStream(new DomainIdentifier('course', 'math-101'));
        $event = self::event(domainIds: ['student' => 'alice']);

        self::assertFalse($query->matches($event));
    }

    public function test_matches_and_stream_from_empty_query_adds_the_identifier_as_a_branch(): void
    {
        $query = StreamQuery::forStream()
            ->andStream(new DomainIdentifier('course', 'math-101'))
        ;

        self::assertFalse($query->isEmpty());
        self::assertTrue($query->matches(self::event(domainIds: ['course' => 'math-101'])));
        self::assertFalse($query->matches(self::event(domainIds: ['course' => 'other-101'])));
    }

    public function test_matches_and_stream_extends_legacy_domain_identifier_query(): void
    {
        $query = new StreamQuery(['course' => 'math-101'])
            ->andStream(new DomainIdentifier('student', 'alice'))
        ;

        self::assertTrue($query->matches(self::event(domainIds: [
            'course' => 'math-101',
            'student' => 'alice',
        ])));
        self::assertFalse($query->matches(self::event(domainIds: [
            'course' => 'math-101',
        ])));
        self::assertFalse($query->matches(self::event(domainIds: [
            'student' => 'alice',
        ])));
    }

    public function test_matches_and_stream_extends_only_the_last_or_branch(): void
    {
        $query = StreamQuery::anyOf(
            StreamQuery::forStream(new DomainIdentifier('course', 'math-101')),
            StreamQuery::forStream(new DomainIdentifier('course', 'math-102')),
        )->andStream(new DomainIdentifier('student', 'alice'));

        self::assertTrue($query->matches(self::event(domainIds: ['course' => 'math-101'])));
        self::assertTrue($query->matches(self::event(domainIds: [
            'course' => 'math-102',
            'student' => 'alice',
        ])));
        self::assertFalse($query->matches(self::event(domainIds: ['course' => 'math-102'])));
    }

    public function test_matches_for_stream_with_multiple_identifiers_matches_event_with_all(): void
    {
        $query = StreamQuery::forStream(
            new DomainIdentifier('course', 'math-101'),
            new DomainIdentifier('student', 'alice'),
        );
        $event = self::event(domainIds: ['course' => 'math-101', 'student' => 'alice']);

        self::assertTrue($query->matches($event));
    }

    public function test_matches_for_stream_with_multiple_identifiers_does_not_match_when_one_is_missing(): void
    {
        $query = StreamQuery::forStream(
            new DomainIdentifier('course', 'math-101'),
            new DomainIdentifier('student', 'alice'),
        );
        $event = self::event(domainIds: ['course' => 'math-101']);

        self::assertFalse($query->matches($event));
    }

    public function test_matches_any_of_matches_event_that_matches_any_branch(): void
    {
        $query = StreamQuery::anyOf(
            StreamQuery::forStream(new DomainIdentifier('course', 'math-101')),
            StreamQuery::forStream(new DomainIdentifier('student', 'alice')),
        );

        self::assertTrue($query->matches(self::event(domainIds: ['course' => 'math-101'])));
        self::assertTrue($query->matches(self::event(domainIds: ['student' => 'alice'])));
        self::assertFalse($query->matches(self::event(domainIds: ['other' => 'x'])));
    }

    public function test_matches_any_of_accepts_legacy_domain_identifier_branches(): void
    {
        $query = StreamQuery::anyOf(
            new StreamQuery(['course' => 'math-101']),
            new StreamQuery(['student' => 'alice']),
        );

        self::assertTrue($query->matches(self::event(domainIds: ['course' => 'math-101'])));
        self::assertTrue($query->matches(self::event(domainIds: ['student' => 'alice'])));
        self::assertFalse($query->matches(self::event(domainIds: ['other' => 'x'])));
    }

    public function test_matches_of_types_filters_by_event_type(): void
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

        self::assertSame(['course.created', 'course.updated'], $query->eventNames);
        self::assertSame([['course.created', 'course.updated']], $query->orBranchEventNames);
    }

    public function test_of_types_records_branch_event_names_for_legacy_domain_identifier_query(): void
    {
        $query = new StreamQuery(['course' => 'math-101'])
            ->ofTypes('course.created')
        ;

        self::assertSame([['course.created']], $query->orBranchEventNames);
    }

    public function test_of_types_records_branch_event_names_for_existing_or_criteria(): void
    {
        $query = StreamQuery::anyOf(
            StreamQuery::forStream(new DomainIdentifier('course', 'math-101')),
            StreamQuery::forStream(new DomainIdentifier('student', 'alice')),
        )->ofTypes('course.created');

        self::assertSame([['course.created'], ['course.created']], $query->orBranchEventNames);
    }

    public function test_matches_of_types_filters_by_event_type_within_or_criteria(): void
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
        self::assertFalse($query->matches(self::event(
            eventType: 'course.created',
            domainIds: ['course' => 'math-102'],
        )));
    }

    public function test_matches_deeply_nested_or_criteria(): void
    {
        $query = StreamQuery::anyOf(
            StreamQuery::forStream(new DomainIdentifier('course', 'math-101'))
                ->ofTypes('course.created'),
            StreamQuery::anyOf(
                StreamQuery::forStream(new DomainIdentifier('course', 'math-102'))
                    ->ofTypes('course.updated'),
                StreamQuery::forStream(new DomainIdentifier('course', 'math-103'))
                    ->ofTypes('course.deleted'),
            ),
        );

        self::assertTrue($query->matches(self::event(
            eventType: 'course.created',
            domainIds: ['course' => 'math-101'],
        )));
        self::assertTrue($query->matches(self::event(
            eventType: 'course.updated',
            domainIds: ['course' => 'math-102'],
        )));
        self::assertTrue($query->matches(self::event(
            eventType: 'course.deleted',
            domainIds: ['course' => 'math-103'],
        )));
        self::assertFalse($query->matches(self::event(
            eventType: 'course.deleted',
            domainIds: ['course' => 'math-101'],
        )));
        self::assertFalse($query->matches(self::event(
            eventType: 'course.created',
            domainIds: ['course' => 'math-102'],
        )));
        self::assertFalse($query->matches(self::event(
            eventType: 'course.created',
            domainIds: ['course' => 'math-103'],
        )));
    }

    public function test_matches_empty_of_types_means_all_event_types_match(): void
    {
        $query = StreamQuery::forStream(new DomainIdentifier('course', 'math-101'));

        self::assertTrue($query->matches(self::event(
            eventType: 'any.event.type',
            domainIds: ['course' => 'math-101'],
        )));
    }

    public function test_matches_legacy_constructor_with_domain_identifiers(): void
    {
        $query = new StreamQuery(['course' => 'math-101'], []);
        $event = self::event(domainIds: ['course' => 'math-101']);

        self::assertTrue($query->matches($event));
    }

    public function test_matches_legacy_constructor_with_domain_identifiers_rejects_missing_or_different_values(): void
    {
        $query = new StreamQuery(['course' => 'math-101', 'student' => 'alice']);

        self::assertFalse($query->matches(self::event(domainIds: ['course' => 'math-101'])));
        self::assertFalse($query->matches(self::event(domainIds: [
            'course' => 'math-101',
            'student' => 'bob',
        ])));
    }

    public function test_matches_legacy_constructor_with_domain_identifiers_and_event_names(): void
    {
        $query = new StreamQuery(['course' => 'math-101'], ['course.created']);

        self::assertTrue($query->matches(self::event(
            eventType: 'course.created',
            domainIds: ['course' => 'math-101'],
        )));
        self::assertFalse($query->matches(self::event(
            eventType: 'course.updated',
            domainIds: ['course' => 'math-101'],
        )));
    }
}
