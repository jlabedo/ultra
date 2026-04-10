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

    public function test_matches_of_types_filters_by_event_type(): void
    {
        $query = StreamQuery::forStream(new DomainIdentifier('course', 'math-101'))
            ->ofTypes('course.created', 'course.updated');

        self::assertTrue($query->matches(self::event(
            eventType: 'course.created',
            domainIds: ['course' => 'math-101'],
        )));
        self::assertFalse($query->matches(self::event(
            eventType: 'course.deleted',
            domainIds: ['course' => 'math-101'],
        )));
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
