<?php

declare(strict_types=1);

namespace Ultra\EventStore;

/**
 * One OR branch of a stream query.
 *
 * Domain identifiers are matched with AND semantics. An empty domain identifier list means all streams.
 * Event types are matched with OR semantics, where an empty event type list means all event types.
 *
 * When bucketRange is set, the branch matches events whose domain identifier value in the given
 * dimension hashes to a bucket within [bucketFrom, bucketTo]. Used for segment-scoped projection
 * queries; requires the bucket column in event_domain_ids.
 */
readonly class StreamQueryBranch
{
    /** @var array<string, string> */
    public array $domainIdentifiers;

    /** @var list<string> */
    public array $eventTypes;

    /**
     * When set, this branch matches by bucket range instead of exact identifier value.
     * @var array{dimension: string, from: int, to: int}|null
     */
    public ?array $bucketRange;

    /**
     * @param array<string, string>                            $domainIdentifiers
     * @param list<string>                                     $eventTypes
     * @param array{dimension: string, from: int, to: int}|null $bucketRange
     */
    public function __construct(array $domainIdentifiers, array $eventTypes = [], ?array $bucketRange = null)
    {
        ksort($domainIdentifiers);

        $this->domainIdentifiers = $domainIdentifiers;
        $this->eventTypes = array_values(array_unique($eventTypes));
        $this->bucketRange = $bucketRange;
    }

    public function isGlobal(): bool
    {
        return [] === $this->domainIdentifiers && null === $this->bucketRange;
    }

    public function withEventTypes(string ...$eventTypes): self
    {
        return new self($this->domainIdentifiers, array_values($eventTypes), $this->bucketRange);
    }

    public function withBucketRange(string $dimension, int $from, int $to): self
    {
        return new self($this->domainIdentifiers, $this->eventTypes, ['dimension' => $dimension, 'from' => $from, 'to' => $to]);
    }

    public function matches(Event $event): bool
    {
        if ([] !== $this->eventTypes && !in_array($event->eventType, $this->eventTypes, true)) {
            return false;
        }

        foreach ($this->domainIdentifiers as $dimension => $value) {
            if (!isset($event->domainIds[$dimension]) || $event->domainIds[$dimension] !== $value) {
                return false;
            }
        }

        if (null !== $this->bucketRange) {
            $dimension = $this->bucketRange['dimension'];
            $value = $event->domainIds[$dimension] ?? null;
            if (null === $value) {
                return false;
            }
            $bucket = BucketHasher::bucketFor($value);
            if ($bucket < $this->bucketRange['from'] || $bucket > $this->bucketRange['to']) {
                return false;
            }
        }

        return true;
    }
}
