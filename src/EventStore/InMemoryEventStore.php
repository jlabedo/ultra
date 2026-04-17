<?php

declare(strict_types=1);

namespace Ultra\EventStore;

use Ultra\EventStore\Exception\ConcurrencyException;

final class InMemoryEventStore implements EventStore
{
    /** @var list<Event> */
    private array $events = [];

    public function load(
        StreamQuery $query,
        ?Checkpoint $from = null,
        ?Checkpoint $to = null,
        int $batchSize = 1000,
    ): LoadResult
    {
        if ($batchSize < 1) {
            throw new \InvalidArgumentException('Event-store batch size must be at least 1.');
        }

        if ($query->isEmpty()) {
            return new LoadResult([], $to ?? $from ?? Checkpoint::beginning(), $query);
        }

        return $this->loadEvents($query, $from, $to, $batchSize);
    }

    public function append(array $events, AppendCondition ...$appendConditions): void
    {
        foreach ($appendConditions as $appendCondition) {
            if ([] !== $this->load($appendCondition->query, from: $appendCondition->checkpoint, batchSize: 1)->events) {
                throw new ConcurrencyException($appendCondition->query->describe());
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

    private function loadEvents(StreamQuery $query, ?Checkpoint $from, ?Checkpoint $to, int $batchSize): LoadResult
    {
        $rows = [];
        $limit = null === $to ? $batchSize : $batchSize + 1;

        foreach ($this->events as $index => $event) {
            $sequence = new Sequence($index + 1);
            if (! $query->matches($event) || $this->wasAlreadySeen($sequence, $from) || $this->isOutsideBound($sequence, $to)) {
                continue;
            }

            $rows[] = [$sequence, $event];
            if (count($rows) === $limit) {
                break;
            }
        }

        $reachedToBound = null !== $to && count($rows) <= $batchSize;
        if (null !== $to && ! $reachedToBound) {
            $rows = array_slice($rows, 0, $batchSize);
        }

        $events = array_map(
            static fn(array $row): Event => $row[1],
            $rows,
        );

        if ($reachedToBound) {
            return new LoadResult($events, $to, $query);
        }

        $checkpoint = $from ?? Checkpoint::beginning();
        foreach ($rows as [$sequence]) {
            $checkpoint = $checkpoint->record($sequence, inGap: false);
        }

        return new LoadResult($events, $checkpoint, $query);
    }

    private function wasAlreadySeen(Sequence $sequence, ?Checkpoint $from): bool
    {
        return null !== $from && $from->contains($sequence);
    }

    private function isOutsideBound(Sequence $sequence, ?Checkpoint $to): bool
    {
        return null !== $to && ! $to->contains($sequence);
    }
}
