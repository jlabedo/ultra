<?php

declare(strict_types=1);

namespace Ultra\EventStore\Tests;

use PHPUnit\Framework\Attributes\CoversNamespace;
use PHPUnit\Framework\Attributes\DataProvider;
use Ultra\EventStore\DomainIdentifier;
use Ultra\EventStore\StreamQuery;
use Ultra\EventStore\Tests\Support\StorageTestCase;

/** @internal */
#[CoversNamespace('Ultra\EventStore')]
#[CoversNamespace('Ultra\EventStore_Postgres')]
final class EventValuePersistenceTest extends StorageTestCase
{
    #[DataProvider('backends')]
    public function test_batched_events_keep_order_payload_identifiers_and_metadata(string $backend): void
    {
        $store = $this->fixture($backend)->store;
        $domainIds = ['course' => 'course-"123"\\example', 'student' => 's1'];
        $query = StreamQuery::forStream(new DomainIdentifier('course', $domainIds['course']));
        $metadata = ['message_id' => 'event-1', 'correlation_id' => 'trace-1', 'tags' => ['a', 'b']];
        $payload = ['name' => 'Événement', 'nested' => ['slot' => 1], 'active' => true, 'optional' => null];
        $store->append([
            self::event('unregistered.created', $domainIds, $payload, $metadata),
            self::event('unregistered.updated', ['course' => $domainIds['course'], 'student' => 's2'], ['slot' => 2]),
        ], $store->load($query)->appendCondition());

        $events = $store->load($query)->events;
        self::assertSame(['unregistered.created', 'unregistered.updated'], array_column($events, 'eventType'));
        self::assertSame(self::sortAssociativeKeys($domainIds), self::sortAssociativeKeys($events[0]->domainIds));
        self::assertSame(self::sortAssociativeKeys($payload), self::sortAssociativeKeys($events[0]->payload));
        self::assertSame(self::sortAssociativeKeys($metadata), self::sortAssociativeKeys($events[0]->metadata));
        self::assertSame([], $events[1]->metadata);
        foreach ($events as $event) {
            self::assertInstanceOf(\DateTimeImmutable::class, $event->recordedAt);
        }
        self::assertSame([2], array_column(array_column($store->load(StreamQuery::forStream(
            new DomainIdentifier('course', $domainIds['course']),
            new DomainIdentifier('student', 's2'),
        ))->events, 'payload'), 'slot'));
    }

    /**
     * Ignore associative-key order from JSONB without changing list order or scalar types.
     *
     * @param array<array-key, mixed> $values
     * @return array<array-key, mixed>
     */
    private static function sortAssociativeKeys(array $values): array
    {
        foreach ($values as $key => $value) {
            if (is_array($value)) {
                $values[$key] = self::sortAssociativeKeys($value);
            }
        }
        if (!array_is_list($values)) {
            ksort($values);
        }

        return $values;
    }
}
