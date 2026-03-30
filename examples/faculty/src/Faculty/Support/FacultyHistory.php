<?php

declare(strict_types=1);

namespace App\Faculty\Support;

use Ultra\Events\EventRegistryInterface;
use Ultra\EventStore\DomainIdentifier;
use Ultra\EventStore\EventStore;
use Ultra\EventStore\StoredEvent;
use Ultra\EventStore\StreamQuery;
use Ultra\Mapper\UltraNormalizer;

final readonly class FacultyHistory
{
    public function __construct(
        private EventStore $eventStore,
        private EventRegistryInterface $eventRegistry,
        private UltraNormalizer $normalizer,
    ) {}

    /**
     * @return list<object>
     */
    public function loadFacultyEvents(string ...$eventTypes): array
    {
        $query = StreamQuery::forStream(new DomainIdentifier('faculty', FacultyIds::DEFAULT));
        if ([] !== $eventTypes) {
            $query = $query->ofTypes(...$eventTypes);
        }

        return $this->rehydrate($this->eventStore->load($query)->events);
    }

    /**
     * @return list<object>
     */
    public function loadCourseEvents(string $courseId, string ...$eventTypes): array
    {
        $query = StreamQuery::forStream(new DomainIdentifier('course', $courseId));
        if ([] !== $eventTypes) {
            $query = $query->ofTypes(...$eventTypes);
        }

        return $this->rehydrate($this->eventStore->load($query)->events);
    }

    /**
     * @return list<object>
     */
    public function loadStudentEvents(string $studentId, string ...$eventTypes): array
    {
        $query = StreamQuery::forStream(new DomainIdentifier('student', $studentId));
        if ([] !== $eventTypes) {
            $query = $query->ofTypes(...$eventTypes);
        }

        return $this->rehydrate($this->eventStore->load($query)->events);
    }

    /**
     * @param list<StoredEvent> $storedEvents
     *
     * @return list<object>
     */
    private function rehydrate(array $storedEvents): array
    {
        $events = [];

        foreach ($storedEvents as $storedEvent) {
            $eventClass = $this->eventRegistry->getClass($storedEvent->eventType);
            if (null === $eventClass) {
                throw new \LogicException(sprintf('Unknown event type "%s".', $storedEvent->eventType));
            }

            $events[] = $this->normalizer->fromScalar($storedEvent->payload, $eventClass);
        }

        return $events;
    }
}
