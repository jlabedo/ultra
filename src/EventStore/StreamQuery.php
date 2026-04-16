<?php

declare(strict_types=1);

namespace Ultra\EventStore;

/**
 * Immutable query for loading events. Use forStream() to start a branch (one or more identifiers, AND),
 * ofTypes() to filter by event type name. Combine branches with anyOf(). Pass to EventStore::load().
 */
readonly class StreamQuery
{
    /**
     * @var list<StreamQueryBranch>
     */
    public array $branches;

    /**
     * @param list<StreamQueryBranch> $branches
     */
    public function __construct(array $branches = [])
    {
        $this->branches = array_values(array_filter(
            $branches,
            static function (StreamQueryBranch $branch): bool {
                return ! $branch->isEmpty();
            },
        ));
    }

    public static function none(): self
    {
        return new self();
    }

    /**
     * Start a branch: events that have all of these domain identifiers (AND).
     * Multiple identifiers mean "event is in all these streams".
     */
    public static function forStream(DomainIdentifier ...$identifiers): self
    {
        if ([] === $identifiers) {
            return self::none();
        }

        return new self([new StreamQueryBranch(self::identifiersToMap($identifiers))]);
    }

    /**
     * Restrict to these event type names (e.g. 'course.created'). Empty = all types. Returns a new query.
     * When combined with anyOf(), each branch keeps its own type filter (per-branch filtering).
     */
    public function ofTypes(string ...$eventTypeNames): self
    {
        $branches = [];
        foreach ($this->branches as $branch) {
            $branches[] = $branch->withEventTypes(...array_values($eventTypeNames));
        }

        return new self($branches);
    }

    /**
     * Combine multiple query branches with OR: an event matches if it matches any branch.
     * Each branch keeps its own event type filter (per-branch); an event must match clause and types of the same branch.
     */
    public static function anyOf(StreamQuery ...$branches): self
    {
        $queryBranches = [];
        foreach ($branches as $query) {
            array_push($queryBranches, ...$query->branches);
        }

        return new self($queryBranches);
    }

    public function isEmpty(): bool
    {
        return [] === $this->branches;
    }

    /**
     * Return true if the stored event matches this query (domain identifiers and optional event type filter).
     */
    public function matches(Event $event): bool
    {
        if ($this->isEmpty()) {
            return false;
        }

        foreach ($this->branches as $branch) {
            if ($branch->matches($event)) {
                return true;
            }
        }

        return false;
    }

    public function describe(): string
    {
        $description = json_encode([
            'branches' => array_map(
                static fn(StreamQueryBranch $branch): array => [
                    'domainIdentifiers' => $branch->domainIdentifiers,
                    'eventTypes' => $branch->eventTypes,
                ],
                $this->branches,
            ),
        ]);

        return is_string($description) ? $description : 'query';
    }

    /**
     * @param list<DomainIdentifier> $identifiers
     *
     * @return array<string, string>
     */
    private static function identifiersToMap(array $identifiers): array
    {
        $map = [];
        foreach ($identifiers as $id) {
            $map[$id->dimension] = $id->value;
        }

        return $map;
    }
}
