# Projection

Projection-side contracts and types.

## Write Side vs Projection Side

The event store write side uses `Ultra\EventStore\Event`.

Projection code uses `Ultra\Projection\StoredEvent`, which extends that event data with:

- `eventId`

That split is intentional:

- write-side decisions should care about event content and ordering within a loaded batch
- projection runtimes need a persisted identifier for checkpoints, inbox routing, and replay progress

## EventFeed

`EventFeed` is the projection boundary for persisted event replay:

```php
interface EventFeed
{
    public function readAfter(int $afterEventId, int $limit, array $eventTypes = []): array;

    public function readByIds(array $eventIds): array;
}
```

Both methods return `list<StoredEvent>`.

## Projection Handlers

Projection handlers type-hint `StoredEvent`, not `Event`.

That gives them access to:

- `eventId` for checkpointing and partition routing
- `eventType`, `payload`, `domainIds`, and `recordedAt` inherited from `Event`

If you only need event content for decisions, stay on the `Ultra\EventStore` side. If you need persisted replay identity, move to the projection side.
