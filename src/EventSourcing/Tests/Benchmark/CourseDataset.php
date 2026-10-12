<?php

declare(strict_types=1);

namespace Ultra\EventStore\Tests\Benchmark;

use Ultra\EventStore\DomainIdentifier;
use Ultra\EventStore\Event;
use Ultra\EventStore\EventStore;
use Ultra\EventStore\StreamQuery;

/** Deterministic course histories shared by the benchmark and its correctness checks. */
final class CourseDataset
{
    /** @return array<string, array{query: StreamQuery, expectedEvents: int}> */
    public static function seed(EventStore $store, int $seedEvents, int $batchSize): array
    {
        if ($seedEvents < 4 || $batchSize < 1) {
            throw new \InvalidArgumentException('Seed at least four events in positive batches.');
        }

        $courseCount = max(6, min(240, max(6, intdiv($seedEvents, 50))));
        $courseCount = min($courseCount, $seedEvents - 1);
        $facultyCount = max(4, min(24, max(1, intdiv($courseCount, 8))));

        $hotCourseCount = min(max(1, intdiv($courseCount, 20)), 8);
        $warmCourseCount = min(max(2, intdiv($courseCount, 5)), 32);
        if (($hotCourseCount + $warmCourseCount) >= $courseCount) {
            $warmCourseCount = max(1, $courseCount - $hotCourseCount - 1);
        }
        if (($hotCourseCount + $warmCourseCount) >= $courseCount) {
            $hotCourseCount = max(1, $courseCount - $warmCourseCount - 1);
        }

        $courseCreatedCount = $courseCount;
        $minimumStudentSubscriptions = min(max(1, $courseCount), max(0, $seedEvents - $courseCreatedCount));
        $capacityChangeCount = min(
            max($courseCount * 3, intdiv($seedEvents, 12)),
            max(0, $seedEvents - $courseCreatedCount - $minimumStudentSubscriptions),
        );
        $studentSubscribedCount = max(0, $seedEvents - $courseCreatedCount - $capacityChangeCount);

        $courses = [];
        $courseIndexesByBand = [
            'hot' => [],
            'warm' => [],
            'cold' => [],
        ];

        for ($courseIndex = 0; $courseIndex < $courseCount; ++$courseIndex) {
            $band = match (true) {
                $courseIndex < $hotCourseCount => 'hot',
                $courseIndex < ($hotCourseCount + $warmCourseCount) => 'warm',
                default => 'cold',
            };

            $courses[$courseIndex] = [
                'courseId' => sprintf('course-read-%03d', $courseIndex),
                'facultyId' => sprintf('faculty-read-%02d', $courseIndex % $facultyCount),
                'band' => $band,
                'subscriptionCount' => 0,
                'capacityChangeCount' => 0,
                'sampleStudentId' => null,
            ];
            $courseIndexesByBand[$band][] = $courseIndex;
        }

        $sequence = 0;
        $batchedEvents = [];

        foreach ($courses as $courseIndex => $course) {
            $batchedEvents[] = self::courseCreatedEvent(
                courseId: $course['courseId'],
                facultyId: $course['facultyId'],
                sequence: $sequence,
            );
            ++$sequence;

            if (count($batchedEvents) >= $batchSize) {
                $store->append($batchedEvents);
                $batchedEvents = [];
            }
        }

        $remainingCapacityChanges = $capacityChangeCount;
        $remainingStudentSubscriptions = $studentSubscribedCount;
        $capacityCursor = 0;
        $subscriptionCursor = 0;
        $subscriptionCursorByBand = [
            'hot' => 0,
            'warm' => 0,
            'cold' => 0,
        ];
        $subscriptionBandSequence = [
            'hot',
            'hot',
            'hot',
            'hot',
            'hot',
            'hot',
            'hot',
            'hot',
            'hot',
            'hot',
            'hot',
            'warm',
            'warm',
            'warm',
            'warm',
            'warm',
            'warm',
            'cold',
            'cold',
            'cold',
        ];

        while ($remainingCapacityChanges > 0 || $remainingStudentSubscriptions > 0) {
            $shouldSeedCapacityChange =
                $remainingCapacityChanges > 0 && (0 === ($sequence % 12) || 0 === $remainingStudentSubscriptions);

            if ($shouldSeedCapacityChange) {
                $courseIndex = $capacityCursor % $courseCount;
                ++$capacityCursor;
                ++$courses[$courseIndex]['capacityChangeCount'];

                $batchedEvents[] = self::courseCapacityChangedEvent(
                    courseId: $courses[$courseIndex]['courseId'],
                    capacity: 40 + (($sequence + $courseIndex) % 180),
                    sequence: $sequence,
                );
                --$remainingCapacityChanges;
                ++$sequence;
            } else {
                $band = $subscriptionBandSequence[$subscriptionCursor % count($subscriptionBandSequence)];
                if ([] === $courseIndexesByBand[$band]) {
                    $band = 'warm' === $band ? 'hot' : 'warm';
                }

                $bandIndexes = $courseIndexesByBand[$band];
                $bandCourseIndex = $bandIndexes[$subscriptionCursorByBand[$band] % count($bandIndexes)];
                ++$subscriptionCursor;
                ++$subscriptionCursorByBand[$band];

                $studentNumber = $courses[$bandCourseIndex]['subscriptionCount'];
                $studentId = sprintf('student-read-%03d-%05d', $bandCourseIndex, $studentNumber);
                ++$courses[$bandCourseIndex]['subscriptionCount'];
                if (null === $courses[$bandCourseIndex]['sampleStudentId']) {
                    $courses[$bandCourseIndex]['sampleStudentId'] = $studentId;
                }

                $batchedEvents[] = self::studentSubscribedEvent(
                    courseId: $courses[$bandCourseIndex]['courseId'],
                    facultyId: $courses[$bandCourseIndex]['facultyId'],
                    studentId: $studentId,
                    sequence: $sequence,
                );
                --$remainingStudentSubscriptions;
                ++$sequence;
            }

            if (count($batchedEvents) >= $batchSize) {
                $store->append($batchedEvents);
                $batchedEvents = [];
            }
        }

        if ([] !== $batchedEvents) {
            $store->append($batchedEvents);
        }

        $hotCourse = $courses[$courseIndexesByBand['hot'][0]];
        $warmCourse = $courses[$courseIndexesByBand['warm'][0]];
        $lifecycleCourse = $courses[$courseIndexesByBand['hot'][min(1, count($courseIndexesByBand['hot']) - 1)]];
        $pairCourse = $hotCourse;
        if (null === $pairCourse['sampleStudentId']) {
            foreach ($courses as $course) {
                if (null !== $course['sampleStudentId']) {
                    $pairCourse = $course;

                    break;
                }
            }
        }

        $dashboardCourseIndexes = array_slice(
            $courseIndexesByBand['warm'],
            0,
            min(4, count($courseIndexesByBand['warm'])),
        );
        if ([] === $dashboardCourseIndexes) {
            $dashboardCourseIndexes = array_slice(
                $courseIndexesByBand['hot'],
                0,
                min(4, count($courseIndexesByBand['hot'])),
            );
        }

        $dashboardEventCount = 0;
        $dashboardCourseIds = [];
        foreach ($dashboardCourseIndexes as $courseIndex) {
            $dashboardCourseIds[] = $courses[$courseIndex]['courseId'];
            $dashboardEventCount += $courses[$courseIndex]['capacityChangeCount'];
        }

        return self::scenarios([
            'hotCourse' => [
                'courseId' => $hotCourse['courseId'],
                'subscriptionCount' => $hotCourse['subscriptionCount'],
            ],
            'warmCourse' => [
                'courseId' => $warmCourse['courseId'],
                'subscriptionCount' => $warmCourse['subscriptionCount'],
            ],
            'lifecycleCourse' => [
                'courseId' => $lifecycleCourse['courseId'],
                'eventCount' => 1 + $lifecycleCourse['capacityChangeCount'],
            ],
            'pairLookup' => [
                'courseId' => $pairCourse['courseId'],
                'studentId' => (string) $pairCourse['sampleStudentId'],
            ],
            'dashboard' => [
                'courseIds' => $dashboardCourseIds,
                'eventCount' => $dashboardEventCount,
            ],
        ]);
    }

    public static function seedReadAppendDataset(EventStore $store, int $streamCount): void
    {
        $events = [];
        $sequence = 0;

        for ($streamIndex = 0; $streamIndex < $streamCount; ++$streamIndex) {
            $courseId = self::readAppendCourseId($streamIndex);
            $facultyId = self::readAppendFacultyId($streamIndex);

            $events[] = self::courseCreatedEvent($courseId, $facultyId, $sequence);
            ++$sequence;
            $events[] = self::courseCapacityChangedEvent(
                courseId: $courseId,
                capacity: 10 + ($streamIndex % 12),
                sequence: $sequence,
            );
            ++$sequence;

            $initialSubscriptions = 4 + ($streamIndex % 8);
            for ($studentIndex = 0; $studentIndex < $initialSubscriptions; ++$studentIndex) {
                $events[] = self::studentSubscribedEvent(
                    courseId: $courseId,
                    facultyId: $facultyId,
                    studentId: self::readAppendStudentId($streamIndex, $studentIndex),
                    sequence: $sequence,
                );
                ++$sequence;
            }

            if (count($events) >= 100) {
                $store->append($events);
                $events = [];
            }
        }

        if ([] !== $events) {
            $store->append($events);
        }
    }

    public static function readAppendCourseQuery(string $courseId): StreamQuery
    {
        return StreamQuery::forStream(new DomainIdentifier('courseId', $courseId))->ofTypes(
            'course.created',
            'course.capacity_changed',
            'student.subscribed',
        );
    }

    /** @param list<Event> $events
     * @return array{capacity: int, subscriptionCount: int}
     */
    public static function courseEnrollmentState(array $events): array
    {
        $capacity = 30;
        $subscriptionCount = 0;

        foreach ($events as $event) {
            if ('course.capacity_changed' === $event->eventType && is_int($event->payload['capacity'] ?? null)) {
                $capacity = $event->payload['capacity'];

                continue;
            }

            if ('student.subscribed' === $event->eventType) {
                ++$subscriptionCount;
            }
        }

        return [
            'capacity' => $capacity,
            'subscriptionCount' => $subscriptionCount,
        ];
    }

    public static function readAppendCourseId(int $streamIndex): string
    {
        return sprintf('course-guarded-%03d', $streamIndex);
    }

    public static function readAppendFacultyId(int $streamIndex): string
    {
        return sprintf('faculty-guarded-%02d', $streamIndex % 10);
    }

    public static function readAppendStudentId(int $streamIndex, int $studentIndex): string
    {
        return sprintf('student-guarded-%03d-%05d', $streamIndex, $studentIndex);
    }

    public static function courseCreatedEvent(string $courseId, string $facultyId, int $sequence): Event
    {
        return new Event(
            eventType: 'course.created',
            payload: [
                'sequence' => $sequence,
                'facultyId' => $facultyId,
                'name' => sprintf('Course %s', $courseId),
            ],
            domainIds: [
                'courseId' => $courseId,
            ],
        );
    }

    public static function courseCapacityChangedEvent(string $courseId, int $capacity, int $sequence): Event
    {
        return new Event(
            eventType: 'course.capacity_changed',
            payload: [
                'sequence' => $sequence,
                'capacity' => $capacity,
            ],
            domainIds: [
                'courseId' => $courseId,
            ],
        );
    }

    public static function studentSubscribedEvent(
        string $courseId,
        string $facultyId,
        string $studentId,
        int $sequence,
        string $status = 'confirmed',
    ): Event {
        return new Event(
            eventType: 'student.subscribed',
            payload: [
                'sequence' => $sequence,
                'status' => $status,
            ],
            domainIds: [
                'facultyId' => $facultyId,
                'courseId' => $courseId,
                'studentId' => $studentId,
            ],
        );
    }

    /** @param array<string, array<string, mixed>> $samples
     * @return array<string, array{query: StreamQuery, expectedEvents: int}>
     */
    private static function scenarios(array $samples): array
    {
        return [
            'hot' => [
                'query' => StreamQuery::forStream(
                    new DomainIdentifier('courseId', $samples['hotCourse']['courseId']),
                )->ofTypes('student.subscribed'),
                'expectedEvents' => $samples['hotCourse']['subscriptionCount'],
            ],
            'warm' => [
                'query' => StreamQuery::forStream(
                    new DomainIdentifier('courseId', $samples['warmCourse']['courseId']),
                )->ofTypes('student.subscribed'),
                'expectedEvents' => $samples['warmCourse']['subscriptionCount'],
            ],
            'lifecycle' => [
                'query' => StreamQuery::forStream(
                    new DomainIdentifier('courseId', $samples['lifecycleCourse']['courseId']),
                )->ofTypes('course.created', 'course.capacity_changed'),
                'expectedEvents' => $samples['lifecycleCourse']['eventCount'],
            ],
            'pair' => [
                'query' => StreamQuery::forStream(
                    new DomainIdentifier('courseId', $samples['pairLookup']['courseId']),
                    new DomainIdentifier('studentId', $samples['pairLookup']['studentId']),
                )->ofTypes('student.subscribed'),
                'expectedEvents' => 1,
            ],
            'dashboard' => [
                'query' => StreamQuery::anyOf(...array_map(
                    static fn(string $id): StreamQuery => StreamQuery::forStream(new DomainIdentifier(
                        'courseId',
                        $id,
                    ))->ofTypes('course.capacity_changed'),
                    $samples['dashboard']['courseIds'],
                )),
                'expectedEvents' => $samples['dashboard']['eventCount'],
            ],
        ];
    }
}
