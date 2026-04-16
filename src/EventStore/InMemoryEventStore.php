<?php

declare(strict_types=1);

namespace Ultra\EventStore;

use Ultra\EventStore\Exception\ConcurrencyException;

final class InMemoryEventStore implements EventStore
{
    /** @var list<Event> */
    private array $events = [];

    public function load(StreamQuery|VersionedQuery $query): LoadResult
    {
        $query = self::streamQuery($query);
        $result = [];
        foreach ($this->events as $event) {
            if (! $query->matches($event)) {
                continue;
            }
            $result[] = $event;
        }

        $versionToken = $this->buildVersionToken($query);

        return new LoadResult($result, new VersionedQuery($versionToken, $query));
    }

    public function append(array $events, VersionedQuery ...$expectedQueries): void
    {
        foreach ($expectedQueries as $expectedQuery) {
            $currentToken = $this->buildVersionToken($expectedQuery->query);
            if (! $currentToken->matches($expectedQuery->token)) {
                throw new ConcurrencyException(self::describeQuery($expectedQuery->query));
            }
        }

        foreach ($events as $event) {
            $this->appendOne($event);
        }
    }

    private function appendOne(Event $event): void
    {
        $this->events[] = new Event(
            eventType: $event->eventType,
            payload: $event->payload,
            domainIds: $event->domainIds,
            recordedAt: new \DateTimeImmutable(),
        );
    }

    private function buildVersionToken(StreamQuery $query): InMemoryEventStoreVersionToken
    {
        $matchingEventCount = 0;
        foreach ($this->events as $event) {
            if (! $query->matches($event)) {
                continue;
            }

            ++$matchingEventCount;
        }

        return new InMemoryEventStoreVersionToken($matchingEventCount);
    }

    private static function describeQuery(StreamQuery $query): string
    {
        $description = json_encode([
            'branches' => array_map(
                static fn(StreamQueryBranch $branch): array => [
                    'domainIdentifiers' => $branch->domainIdentifiers,
                    'eventTypes' => $branch->eventTypes,
                ],
                $query->branches,
            ),
        ]);

        return is_string($description) ? $description : 'query';
    }

    private static function streamQuery(StreamQuery|VersionedQuery $query): StreamQuery
    {
        return $query instanceof VersionedQuery ? $query->query : $query;
    }
}
