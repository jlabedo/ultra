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
        if ($query instanceof VersionedQuery) {
            if (! $query->token instanceof InMemoryEventStoreVersionToken) {
                throw new \InvalidArgumentException('Expected token of type ' . InMemoryEventStoreVersionToken::class);
            }

            $streamQuery = $query->query;
            $matchingEventLimit = $query->token->matchingEventCount();
        } else {
            $streamQuery = $query;
            $matchingEventLimit = null;
        }
        return $this->loadEvents($streamQuery, $matchingEventLimit);
    }

    public function append(array $events, VersionedQuery ...$expectedQueries): void
    {
        foreach ($expectedQueries as $expectedQuery) {
            $currentToken = $this->buildVersionToken($expectedQuery->query);
            if (! $currentToken->matches($expectedQuery->token)) {
                throw new ConcurrencyException($expectedQuery->query->describe());
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
        return $this->loadEvents($query)->versionedQuery->token;
    }

    private function loadEvents(StreamQuery $query, ?int $matchingEventLimit = null): LoadResult
    {
        $result = [];

        $maxIndex = $matchingEventLimit ?? count($this->events) - 1;
        $lastIndex = -1;
        for ($i = 0; $i <= $maxIndex; $i++) {
            $event = $this->events[$i];
            if (! $query->matches($event)) {
                continue;
            }

            $result[] = $event;
            $lastIndex = $i;
        }

        return new LoadResult($result, new VersionedQuery(new InMemoryEventStoreVersionToken($lastIndex), $query));
    }
}
