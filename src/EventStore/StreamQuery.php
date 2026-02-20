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
     * @param array<string, string>   $domainIdentifiers dimension => value (event must match all when orCriteria is empty)
     * @param array<string>           $eventNames       event types to include (empty = all) when orBranchEventNames is null
     * @param list<array<string,string>> $orCriteria    when non-empty: event matches if it matches any criterion (each criterion is dimension => value)
     * @param list<array<string>>|null $orBranchEventNames when set, same length as orCriteria: event type filter per branch (empty = all types)
     */
    public function __construct(
        public array $domainIdentifiers = [],
        public array $eventNames = [],
        public array $orCriteria = [],
        public ?array $orBranchEventNames = null,
    ) {
    }

    /**
     * Start a branch: events that have all of these domain identifiers (AND).
     * Multiple identifiers mean "event is in all these streams".
     */
    public static function forStream(DomainIdentifier ...$identifiers): self
    {
        if ($identifiers === []) {
            return new self([], [], []);
        }

        return new self([], [], [self::identifiersToMap($identifiers)]);
    }

    /**
     * Add another domain identifier to the current branch (AND). Returns a new query.
     */
    public function andStream(DomainIdentifier $identifier): self
    {
        $clauses = $this->orCriteria;
        if ($this->domainIdentifiers !== []) {
            $clauses = [$this->domainIdentifiers];
        }
        if ($clauses === []) {
            return new self([], $this->eventNames, [$identifier->toMap()], $this->orBranchEventNames);
        }
        $last = array_key_last($clauses);
        $clauses[$last] = array_merge($clauses[$last], $identifier->toMap());

        return new self([], $this->eventNames, $clauses, $this->orBranchEventNames);
    }

    /**
     * Restrict to these event type names (e.g. 'course.created'). Empty = all types. Returns a new query.
     * When combined with anyOf(), each branch keeps its own type filter (per-branch filtering).
     */
    public function ofTypes(string ...$eventTypeNames): self
    {
        $names = array_values($eventTypeNames);
        $clauses = $this->orCriteria !== [] ? $this->orCriteria : ($this->domainIdentifiers !== [] ? [$this->domainIdentifiers] : []);
        $orBranchEventNames = $clauses !== [] ? array_fill(0, count($clauses), $names) : null;

        return new self($this->domainIdentifiers, $names, $this->orCriteria, $orBranchEventNames);
    }

    /**
     * Combine multiple query branches with OR: an event matches if it matches any branch.
     * Each branch keeps its own event type filter (per-branch); an event must match clause and types of the same branch.
     */
    public static function anyOf(StreamQuery ...$branches): self
    {
        $orCriteria = [];
        $orBranchEventNames = [];
        foreach ($branches as $q) {
            $clauses = $q->orCriteria;
            if ($q->domainIdentifiers !== []) {
                $clauses = [$q->domainIdentifiers];
            }
            $branchEventNames = $q->orBranchEventNames ?? array_fill(0, count($clauses), $q->eventNames);
            foreach ($clauses as $i => $clause) {
                $orCriteria[] = $clause;
                $orBranchEventNames[] = $branchEventNames[$i] ?? $q->eventNames;
            }
        }

        return new self([], [], $orCriteria, $orBranchEventNames);
    }

    public function isEmpty(): bool
    {
        return $this->domainIdentifiers === [] && $this->orCriteria === [];
    }

    /**
     * Return true if the stored event matches this query (domain identifiers and optional event type filter).
     */
    public function matches(StoredEvent $event): bool
    {
        if ($this->isEmpty()) {
            return false;
        }
        if ($this->orCriteria !== [] && $this->orBranchEventNames !== null && count($this->orBranchEventNames) === count($this->orCriteria)) {
            foreach ($this->orCriteria as $i => $criterion) {
                $clauseMatches = true;
                foreach ($criterion as $dimension => $value) {
                    if (!isset($event->domainIds[$dimension]) || $event->domainIds[$dimension] !== $value) {
                        $clauseMatches = false;
                        break;
                    }
                }
                $branchTypes = $this->orBranchEventNames[$i];
                $typeOk = $branchTypes === [] || in_array($event->eventType, $branchTypes, true);
                if ($clauseMatches && $typeOk) {
                    return true;
                }
            }

            return false;
        }
        if ($this->eventNames !== [] && !in_array($event->eventType, $this->eventNames, true)) {
            return false;
        }
        if ($this->orCriteria !== []) {
            foreach ($this->orCriteria as $criterion) {
                $matches = true;
                foreach ($criterion as $dimension => $value) {
                    if (!isset($event->domainIds[$dimension]) || $event->domainIds[$dimension] !== $value) {
                        $matches = false;
                        break;
                    }
                }
                if ($matches) {
                    return true;
                }
            }

            return false;
        }
        foreach ($this->domainIdentifiers as $dimension => $value) {
            if (!isset($event->domainIds[$dimension]) || $event->domainIds[$dimension] !== $value) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param list<DomainIdentifier> $identifiers
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
