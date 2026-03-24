<?php

declare(strict_types=1);

require_once __DIR__.'/vendor/autoload.php';
require_once __DIR__.'/src/EventStore_Postgres/Tests/PostgresTestSupport.php';

use Doctrine\DBAL\Connection;
use Ultra\EventStore\AppendableEvent;
use Ultra\EventStore\DomainIdentifier;
use Ultra\EventStore\StreamQuery;
use Ultra\EventStore_Postgres\PostgresEventStore;
use Ultra\EventStore_Postgres\PostgresEventStoreSchema;
use function Ultra\EventStore_Postgres\Tests\postgresTestConnection;
use function Ultra\EventStore_Postgres\Tests\postgresTestSchema;
use function Ultra\EventStore_Postgres\Tests\postgresTestStore;

exit(main());

/**
 * @return int process exit code
 */
function main(): int
{
    $config = benchmarkConfig();
    $connection = postgresTestConnection($config['dsn']);
    $prefix = benchmarkTablePrefix();
    $schema = postgresTestSchema($prefix);

    try {
        createSchema($connection, $schema);

        $store = postgresTestStore($connection, $prefix);

        printHeader($config, $prefix);
        printLine('Seeding read dataset...');
        $seedResult = seedReadDataset($store, $config['seedEvents'], $config['seedBatchSize']);
        printSeedResult($seedResult);

        printLine();
        printLine('Benchmarking writes...');
        $writeResult = benchmarkWrites(
            $store,
            operations: $config['writeOperations'],
            batchSize: $config['writeBatchSize'],
            streamCount: $config['writeStreamCount'],
        );
        printBenchmarkResult('Writes', $writeResult);

        printLine();
        printLine('Refreshing planner stats...');
        analyzeEventStore($connection, $prefix);

        $readResults = [];
        printLine();
        printLine('Benchmarking reads...');
        foreach (buildReadScenarios($seedResult['profile']) as $scenario) {
            $readResult = benchmarkReads(
                $store,
                $scenario['query'],
                iterations: $config['readIterations'],
                warmupIterations: $config['readWarmupIterations'],
            );
            $readResults[] = [
                'label' => $scenario['label'],
                'description' => $scenario['description'],
                'result' => $readResult,
            ];
            printReadBenchmarkResult($scenario['label'], $scenario['description'], $readResult);
            printLine();
        }

        $snapshot = buildBenchmarkSnapshot(
            $config,
            $seedResult,
            $writeResult,
            $readResults,
            $connection,
        );

        if (null !== $config['compareTo']) {
            printLine('Comparing against saved benchmark...');
            printBenchmarkComparison(loadBenchmarkSnapshot($config['compareTo']), $snapshot, $config['compareTo']);
            printLine();
        }

        if (null !== $config['saveResult']) {
            saveBenchmarkSnapshot($config['saveResult'], $snapshot);
            printLine(sprintf('Saved benchmark snapshot to %s', $config['saveResult']));
            printLine();
        }

        return 0;
    } catch (\Throwable $exception) {
        fwrite(STDERR, sprintf(
            "Benchmark failed with %s: %s\n",
            $exception::class,
            $exception->getMessage(),
        ));

        return 1;
    } finally {
        dropSchema($connection, $schema);
        $connection->close();
    }
}

/**
 * @return array{
 *     dsn: string,
 *     dsnSource: string,
 *     saveResult: null|string,
 *     compareTo: null|string,
 *     seedEvents: int,
 *     seedBatchSize: int,
 *     writeOperations: int,
 *     writeBatchSize: int,
 *     writeStreamCount: int,
 *     readIterations: int,
 *     readWarmupIterations: int
 * }
 */
function benchmarkConfig(): array
{
    $options = getopt('', [
        'dsn::',
        'save-result::',
        'compare-to::',
        'seed-events::',
        'seed-batch-size::',
        'write-operations::',
        'write-batch-size::',
        'write-stream-count::',
        'read-iterations::',
        'read-warmup-iterations::',
    ]);
    $options = false === $options ? [] : $options;

    $envDsn = getenv('ULTRA_TEST_POSTGRES_DSN');
    $fallbackDsn = 'pgsql://ultra:ultra@127.0.0.1:55432/ultra_test';
    $dsn = resolveStringOption($options, 'dsn', false !== $envDsn && '' !== $envDsn ? $envDsn : $fallbackDsn);
    $seedEvents = resolvePositiveIntOption($options, 'seed-events', 20_000);
    if ($seedEvents < 4) {
        throw new \InvalidArgumentException('Option "--seed-events" must be at least 4 to build the read benchmark suite.');
    }

    return [
        'dsn' => $dsn,
        'dsnSource' => isset($options['dsn'])
            ? '--dsn'
            : (false !== $envDsn && '' !== $envDsn ? 'ULTRA_TEST_POSTGRES_DSN' : 'docker-compose default'),
        'saveResult' => resolveNullableStringOption($options, 'save-result'),
        'compareTo' => resolveNullableStringOption($options, 'compare-to'),
        'seedEvents' => $seedEvents,
        'seedBatchSize' => resolvePositiveIntOption($options, 'seed-batch-size', 100),
        'writeOperations' => resolvePositiveIntOption($options, 'write-operations', 5_000),
        'writeBatchSize' => resolvePositiveIntOption($options, 'write-batch-size', 1),
        'writeStreamCount' => resolvePositiveIntOption($options, 'write-stream-count', 200),
        'readIterations' => resolvePositiveIntOption($options, 'read-iterations', 50),
        'readWarmupIterations' => resolvePositiveIntOption($options, 'read-warmup-iterations', 25),
    ];
}

/**
 * @param array<string, mixed> $options
 */
function resolveStringOption(array $options, string $name, string $default): string
{
    if (! array_key_exists($name, $options)) {
        return $default;
    }

    $value = $options[$name];
    if (is_array($value)) {
        $value = end($value);
    }

    if (! is_string($value) || '' === trim($value)) {
        throw new \InvalidArgumentException(sprintf('Option "--%s" must be a non-empty string.', $name));
    }

    return $value;
}

/**
 * @param array<string, mixed> $options
 */
function resolveNullableStringOption(array $options, string $name): ?string
{
    if (! array_key_exists($name, $options)) {
        return null;
    }

    $value = $options[$name];
    if (is_array($value)) {
        $value = end($value);
    }

    if (false === $value || null === $value || '' === trim((string) $value)) {
        throw new \InvalidArgumentException(sprintf('Option "--%s" must be a non-empty string.', $name));
    }

    return trim((string) $value);
}

/**
 * @param array<string, mixed> $options
 */
function resolvePositiveIntOption(array $options, string $name, int $default): int
{
    if (! array_key_exists($name, $options)) {
        return $default;
    }

    $value = $options[$name];
    if (is_array($value)) {
        $value = end($value);
    }

    if (! is_string($value) && ! is_int($value)) {
        throw new \InvalidArgumentException(sprintf('Option "--%s" must be an integer.', $name));
    }

    $parsed = filter_var($value, FILTER_VALIDATE_INT);
    if (false === $parsed || $parsed <= 0) {
        throw new \InvalidArgumentException(sprintf('Option "--%s" must be a positive integer.', $name));
    }

    return $parsed;
}

function benchmarkTablePrefix(): string
{
    return 'ultra_event_store_bench_'.bin2hex(random_bytes(4)).'_';
}

function createSchema(Connection $connection, PostgresEventStoreSchema $schema): void
{
    foreach ($schema->createStatements() as $statement) {
        $connection->executeStatement($statement);
    }
}

function dropSchema(Connection $connection, PostgresEventStoreSchema $schema): void
{
    foreach ($schema->dropStatements() as $statement) {
        $connection->executeStatement($statement);
    }
}

/**
 * @return array{
 *     operations: int,
 *     eventsPerOperation: int,
 *     totalSeconds: float,
 *     opsPerSecond: float,
 *     eventsPerSecond: float,
 *     profile: array{
 *         facultyCount: int,
 *         courseCount: int,
 *         courseCountsByBand: array{hot: int, warm: int, cold: int},
 *         eventCounts: array{course.created: int, course.capacity_changed: int, student.subscribed: int},
 *         samples: array{
 *             hotCourse: array{courseId: string, subscriptionCount: int},
 *             warmCourse: array{courseId: string, subscriptionCount: int},
 *             lifecycleCourse: array{courseId: string, eventCount: int},
 *             pairLookup: array{courseId: string, studentId: string},
 *             dashboard: array{courseIds: list<string>, eventCount: int}
 *         }
 *     }
 * }
 */
function seedReadDataset(PostgresEventStore $store, int $seedEvents, int $batchSize): array
{
    $courseCount = max(6, min(240, max(6, intdiv($seedEvents, 50))));
    $courseCount = min($courseCount, $seedEvents - 1);
    $facultyCount = max(4, min(24, max(1, intdiv($courseCount, 8))));

    $hotCourseCount = min(max(1, intdiv($courseCount, 20)), 8);
    $warmCourseCount = min(max(2, intdiv($courseCount, 5)), 32);
    if ($hotCourseCount + $warmCourseCount >= $courseCount) {
        $warmCourseCount = max(1, $courseCount - $hotCourseCount - 1);
    }
    if ($hotCourseCount + $warmCourseCount >= $courseCount) {
        $hotCourseCount = max(1, $courseCount - $warmCourseCount - 1);
    }
    $coldCourseCount = max(1, $courseCount - $hotCourseCount - $warmCourseCount);

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
            $courseIndex < $hotCourseCount + $warmCourseCount => 'warm',
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
    $startedAt = hrtime(true);
    $batchedEvents = [];

    foreach ($courses as $courseIndex => $course) {
        $batchedEvents[] = courseCreatedEvent(
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
        'hot', 'hot', 'hot', 'hot', 'hot', 'hot', 'hot', 'hot', 'hot', 'hot', 'hot',
        'warm', 'warm', 'warm', 'warm', 'warm', 'warm',
        'cold', 'cold', 'cold',
    ];

    while ($remainingCapacityChanges > 0 || $remainingStudentSubscriptions > 0) {
        $shouldSeedCapacityChange = $remainingCapacityChanges > 0
            && (0 === $sequence % 12 || 0 === $remainingStudentSubscriptions);

        if ($shouldSeedCapacityChange) {
            $courseIndex = $capacityCursor % $courseCount;
            ++$capacityCursor;
            ++$courses[$courseIndex]['capacityChangeCount'];

            $batchedEvents[] = courseCapacityChangedEvent(
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

            $batchedEvents[] = studentSubscribedEvent(
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

    $dashboardCourseIndexes = array_slice($courseIndexesByBand['warm'], 0, min(4, count($courseIndexesByBand['warm'])));
    if ([] === $dashboardCourseIndexes) {
        $dashboardCourseIndexes = array_slice($courseIndexesByBand['hot'], 0, min(4, count($courseIndexesByBand['hot'])));
    }

    $totalSeconds = elapsedSeconds($startedAt);
    $dashboardEventCount = 0;
    $dashboardCourseIds = [];
    foreach ($dashboardCourseIndexes as $courseIndex) {
        $dashboardCourseIds[] = $courses[$courseIndex]['courseId'];
        $dashboardEventCount += $courses[$courseIndex]['capacityChangeCount'];
    }

    return [
        'operations' => $seedEvents,
        'eventsPerOperation' => 1,
        'totalSeconds' => $totalSeconds,
        'opsPerSecond' => ratePerSecond($seedEvents, $totalSeconds),
        'eventsPerSecond' => ratePerSecond($seedEvents, $totalSeconds),
        'profile' => [
            'facultyCount' => $facultyCount,
            'courseCount' => $courseCount,
            'courseCountsByBand' => [
                'hot' => $hotCourseCount,
                'warm' => $warmCourseCount,
                'cold' => $coldCourseCount,
            ],
            'eventCounts' => [
                'course.created' => $courseCreatedCount,
                'course.capacity_changed' => $capacityChangeCount,
                'student.subscribed' => $studentSubscribedCount,
            ],
            'samples' => [
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
            ],
        ],
    ];
}

/**
 * @param array{
 *     facultyCount: int,
 *     courseCount: int,
 *     courseCountsByBand: array{hot: int, warm: int, cold: int},
 *     eventCounts: array{course.created: int, course.capacity_changed: int, student.subscribed: int},
 *     samples: array{
 *         hotCourse: array{courseId: string, subscriptionCount: int},
 *         warmCourse: array{courseId: string, subscriptionCount: int},
 *         lifecycleCourse: array{courseId: string, eventCount: int},
 *         pairLookup: array{courseId: string, studentId: string},
 *         dashboard: array{courseIds: list<string>, eventCount: int}
 *     }
 * } $profile
 *
 * @return list<array{label: string, description: string, query: StreamQuery}>
 */
function buildReadScenarios(array $profile): array
{
    $dashboardBranches = array_map(
        static fn (string $courseId): StreamQuery => StreamQuery::forStream(
            new DomainIdentifier('courseId', $courseId),
        )->ofTypes('course.capacity_changed'),
        $profile['samples']['dashboard']['courseIds'],
    );

    return [
        [
            'label' => 'Read: Hot course subscriptions',
            'description' => sprintf(
                'courseId=%s | student.subscribed | ~%s events',
                $profile['samples']['hotCourse']['courseId'],
                formatInteger($profile['samples']['hotCourse']['subscriptionCount']),
            ),
            'query' => StreamQuery::forStream(
                new DomainIdentifier('courseId', $profile['samples']['hotCourse']['courseId']),
            )->ofTypes('student.subscribed'),
        ],
        [
            'label' => 'Read: Warm course subscriptions',
            'description' => sprintf(
                'courseId=%s | student.subscribed | ~%s events',
                $profile['samples']['warmCourse']['courseId'],
                formatInteger($profile['samples']['warmCourse']['subscriptionCount']),
            ),
            'query' => StreamQuery::forStream(
                new DomainIdentifier('courseId', $profile['samples']['warmCourse']['courseId']),
            )->ofTypes('student.subscribed'),
        ],
        [
            'label' => 'Read: Course lifecycle',
            'description' => sprintf(
                'courseId=%s | course.created + course.capacity_changed | ~%s events',
                $profile['samples']['lifecycleCourse']['courseId'],
                formatInteger($profile['samples']['lifecycleCourse']['eventCount']),
            ),
            'query' => StreamQuery::forStream(
                new DomainIdentifier('courseId', $profile['samples']['lifecycleCourse']['courseId']),
            )->ofTypes('course.created', 'course.capacity_changed'),
        ],
        [
            'label' => 'Read: Course and student pair',
            'description' => sprintf(
                'courseId=%s + studentId=%s | student.subscribed | 1 event',
                $profile['samples']['pairLookup']['courseId'],
                $profile['samples']['pairLookup']['studentId'],
            ),
            'query' => StreamQuery::forStream(
                new DomainIdentifier('courseId', $profile['samples']['pairLookup']['courseId']),
                new DomainIdentifier('studentId', $profile['samples']['pairLookup']['studentId']),
            )->ofTypes('student.subscribed'),
        ],
        [
            'label' => 'Read: Dashboard course changes',
            'description' => sprintf(
                '%s branches | course.capacity_changed | ~%s events',
                formatInteger(count($profile['samples']['dashboard']['courseIds'])),
                formatInteger($profile['samples']['dashboard']['eventCount']),
            ),
            'query' => StreamQuery::anyOf(...$dashboardBranches),
        ],
    ];
}

/**
 * @return array{
 *     operations: int,
 *     eventsPerOperation: int,
 *     totalSeconds: float,
 *     opsPerSecond: float,
 *     eventsPerSecond: float,
 *     latencyMs: array{avg: float, min: float, p50: float, p95: float, max: float}
 * }
 */
function benchmarkWrites(PostgresEventStore $store, int $operations, int $batchSize, int $streamCount): array
{
    $durationsMs = [];
    $startedAt = hrtime(true);

    for ($operation = 0; $operation < $operations; ++$operation) {
        $events = [];
        $streamIndex = $operation % $streamCount;
        $courseId = sprintf('course-write-%d', $streamIndex);
        $facultyId = sprintf('faculty-write-%d', $streamIndex % 10);

        $operationStartedAt = hrtime(true);
        for ($batchIndex = 0; $batchIndex < $batchSize; ++$batchIndex) {
            $sequence = ($operation * $batchSize) + $batchIndex;
            $events[] = studentSubscribedEvent(
                courseId: $courseId,
                facultyId: $facultyId,
                studentId: sprintf('student-write-%d-%d', $operation, $batchIndex),
                sequence: $sequence,
            );
        }

        $store->append($events);
        $durationsMs[] = elapsedMilliseconds($operationStartedAt);
    }

    $totalSeconds = elapsedSeconds($startedAt);
    $totalEvents = $operations * $batchSize;

    return [
        'operations' => $operations,
        'eventsPerOperation' => $batchSize,
        'totalSeconds' => $totalSeconds,
        'opsPerSecond' => ratePerSecond($operations, $totalSeconds),
        'eventsPerSecond' => ratePerSecond($totalEvents, $totalSeconds),
        'latencyMs' => summarizeDurations($durationsMs),
    ];
}

/**
 * @return array{
 *     operations: int,
 *     eventsPerOperation: int,
 *     totalSeconds: float,
 *     opsPerSecond: float,
 *     eventsPerSecond: float,
 *     latencyMs: array{avg: float, min: float, p50: float, p95: float, max: float}
 * }
 */
function benchmarkReads(PostgresEventStore $store, StreamQuery $query, int $iterations, int $warmupIterations): array
{
    for ($iteration = 0; $iteration < $warmupIterations; ++$iteration) {
        $store->load($query);
    }

    $durationsMs = [];
    $totalEventsLoaded = 0;
    $startedAt = hrtime(true);

    for ($iteration = 0; $iteration < $iterations; ++$iteration) {
        $operationStartedAt = hrtime(true);
        $result = $store->load($query);
        $durationsMs[] = elapsedMilliseconds($operationStartedAt);
        $totalEventsLoaded += count($result->events);
    }

    $totalSeconds = elapsedSeconds($startedAt);

    return [
        'operations' => $iterations,
        'eventsPerOperation' => 0 === $iterations ? 0 : (int) round($totalEventsLoaded / $iterations),
        'totalSeconds' => $totalSeconds,
        'opsPerSecond' => ratePerSecond($iterations, $totalSeconds),
        'eventsPerSecond' => ratePerSecond($totalEventsLoaded, $totalSeconds),
        'latencyMs' => summarizeDurations($durationsMs),
    ];
}

function courseCreatedEvent(string $courseId, string $facultyId, int $sequence): AppendableEvent
{
    return new AppendableEvent(
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

function courseCapacityChangedEvent(string $courseId, int $capacity, int $sequence): AppendableEvent
{
    return new AppendableEvent(
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

function studentSubscribedEvent(string $courseId, string $facultyId, string $studentId, int $sequence): AppendableEvent
{
    return new AppendableEvent(
        eventType: 'student.subscribed',
        payload: [
            'sequence' => $sequence,
            'status' => 'confirmed',
        ],
        domainIds: [
            'facultyId' => $facultyId,
            'courseId' => $courseId,
            'studentId' => $studentId,
        ],
    );
}

/**
 * @param list<float> $durationsMs
 *
 * @return array{avg: float, min: float, p50: float, p95: float, max: float}
 */
function summarizeDurations(array $durationsMs): array
{
    sort($durationsMs);

    $count = count($durationsMs);
    if (0 === $count) {
        return ['avg' => 0.0, 'min' => 0.0, 'p50' => 0.0, 'p95' => 0.0, 'max' => 0.0];
    }

    return [
        'avg' => array_sum($durationsMs) / $count,
        'min' => $durationsMs[0],
        'p50' => percentile($durationsMs, 0.50),
        'p95' => percentile($durationsMs, 0.95),
        'max' => $durationsMs[$count - 1],
    ];
}

/**
 * @param list<float> $sortedDurationsMs
 */
function percentile(array $sortedDurationsMs, float $percentile): float
{
    $count = count($sortedDurationsMs);
    if (0 === $count) {
        return 0.0;
    }

    $index = (int) ceil(($count - 1) * $percentile);

    return $sortedDurationsMs[$index];
}

function elapsedMilliseconds(int $startedAt): float
{
    return elapsedSeconds($startedAt) * 1000;
}

function elapsedSeconds(int $startedAt): float
{
    return (hrtime(true) - $startedAt) / 1_000_000_000;
}

function ratePerSecond(int $units, float $seconds): float
{
    if ($seconds <= 0.0) {
        return 0.0;
    }

    return $units / $seconds;
}

function analyzeEventStore(Connection $connection, string $tablePrefix): void
{
    $connection->executeStatement(sprintf('ANALYZE %sevents', $tablePrefix));
}

/**
 * @param array{
 *     dsnSource: string,
 *     saveResult: null|string,
 *     compareTo: null|string,
 *     seedEvents: int,
 *     seedBatchSize: int,
 *     writeOperations: int,
 *     writeBatchSize: int,
 *     writeStreamCount: int,
 *     readIterations: int,
 *     readWarmupIterations: int
 * } $config
 * @param array{
 *     operations: int,
 *     eventsPerOperation: int,
 *     totalSeconds: float,
 *     opsPerSecond: float,
 *     eventsPerSecond: float,
 *     profile: array{
 *         facultyCount: int,
 *         courseCount: int,
 *         courseCountsByBand: array{hot: int, warm: int, cold: int},
 *         eventCounts: array{course.created: int, course.capacity_changed: int, student.subscribed: int},
 *         samples: array{
 *             hotCourse: array{courseId: string, subscriptionCount: int},
 *             warmCourse: array{courseId: string, subscriptionCount: int},
 *             lifecycleCourse: array{courseId: string, eventCount: int},
 *             pairLookup: array{courseId: string, studentId: string},
 *             dashboard: array{courseIds: list<string>, eventCount: int}
 *         }
 *     }
 * } $seedResult
 * @param array{
 *     operations: int,
 *     eventsPerOperation: int,
 *     totalSeconds: float,
 *     opsPerSecond: float,
 *     eventsPerSecond: float,
 *     latencyMs: array{avg: float, min: float, p50: float, p95: float, max: float}
 * } $writeResult
 * @param list<array{
 *     label: string,
 *     description: string,
 *     result: array{
 *         operations: int,
 *         eventsPerOperation: int,
 *         totalSeconds: float,
 *         opsPerSecond: float,
 *         eventsPerSecond: float,
 *         latencyMs: array{avg: float, min: float, p50: float, p95: float, max: float}
 *     }
 * }> $readResults
 *
 * @return array<string, mixed>
 */
function buildBenchmarkSnapshot(
    array $config,
    array $seedResult,
    array $writeResult,
    array $readResults,
    Connection $connection,
): array {
    return [
        'schemaVersion' => 1,
        'createdAt' => (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format(DATE_ATOM),
        'runtime' => [
            'phpVersion' => PHP_VERSION,
            'postgresVersion' => (string) $connection->fetchOne('SHOW server_version'),
            'gitCommit' => gitHeadCommit(),
        ],
        'config' => [
            'dsnSource' => $config['dsnSource'],
            'seedEvents' => $config['seedEvents'],
            'seedBatchSize' => $config['seedBatchSize'],
            'writeOperations' => $config['writeOperations'],
            'writeBatchSize' => $config['writeBatchSize'],
            'writeStreamCount' => $config['writeStreamCount'],
            'readIterations' => $config['readIterations'],
            'readWarmupIterations' => $config['readWarmupIterations'],
        ],
        'seed' => $seedResult,
        'results' => [
            'write' => $writeResult,
            'reads' => $readResults,
        ],
    ];
}

/**
 * @return array<string, mixed>
 */
function loadBenchmarkSnapshot(string $path): array
{
    if (! is_file($path)) {
        throw new \InvalidArgumentException(sprintf('Benchmark snapshot file not found: %s', $path));
    }

    $contents = file_get_contents($path);
    if (false === $contents) {
        throw new \RuntimeException(sprintf('Unable to read benchmark snapshot file: %s', $path));
    }

    $snapshot = json_decode($contents, true, 512, JSON_THROW_ON_ERROR);
    if (! is_array($snapshot)) {
        throw new \RuntimeException(sprintf('Benchmark snapshot must decode to an object: %s', $path));
    }

    return $snapshot;
}

/**
 * @param array<string, mixed> $snapshot
 */
function saveBenchmarkSnapshot(string $path, array $snapshot): void
{
    $directory = dirname($path);
    if ('.' !== $directory && ! is_dir($directory) && ! mkdir($directory, 0777, true) && ! is_dir($directory)) {
        throw new \RuntimeException(sprintf('Unable to create benchmark snapshot directory: %s', $directory));
    }

    $encoded = json_encode($snapshot, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    if (false === file_put_contents($path, $encoded.PHP_EOL)) {
        throw new \RuntimeException(sprintf('Unable to write benchmark snapshot file: %s', $path));
    }
}

/**
 * @param array<string, mixed> $baseline
 * @param array<string, mixed> $current
 */
function printBenchmarkComparison(array $baseline, array $current, string $baselinePath): void
{
    printLine(sprintf('  Baseline file: %s', $baselinePath));
    printLine(sprintf(
        '  Baseline run: %s | PHP %s | Postgres %s | git %s',
        (string) ($baseline['createdAt'] ?? 'unknown'),
        (string) ($baseline['runtime']['phpVersion'] ?? 'unknown'),
        (string) ($baseline['runtime']['postgresVersion'] ?? 'unknown'),
        (string) ($baseline['runtime']['gitCommit'] ?? 'unknown'),
    ));
    printLine(sprintf(
        '  Current run: %s | PHP %s | Postgres %s | git %s',
        (string) ($current['createdAt'] ?? 'unknown'),
        (string) ($current['runtime']['phpVersion'] ?? 'unknown'),
        (string) ($current['runtime']['postgresVersion'] ?? 'unknown'),
        (string) ($current['runtime']['gitCommit'] ?? 'unknown'),
    ));

    $configDifferences = benchmarkConfigDifferences(
        is_array($baseline['config'] ?? null) ? $baseline['config'] : [],
        is_array($current['config'] ?? null) ? $current['config'] : [],
    );
    if ([] !== $configDifferences) {
        printLine(sprintf('  Config differences: %s', implode(' | ', $configDifferences)));
    }

    $seedDifferences = benchmarkSeedDifferences(
        is_array($baseline['seed']['profile'] ?? null) ? $baseline['seed']['profile'] : [],
        is_array($current['seed']['profile'] ?? null) ? $current['seed']['profile'] : [],
    );
    if ([] !== $seedDifferences) {
        printLine(sprintf('  Seed profile differences: %s', implode(' | ', $seedDifferences)));
    }

    printLine();
    printMetricComparison(
        '  Writes',
        is_array($baseline['results']['write'] ?? null) ? $baseline['results']['write'] : [],
        is_array($current['results']['write'] ?? null) ? $current['results']['write'] : [],
    );

    $baselineReads = indexReadResultsByLabel(is_array($baseline['results']['reads'] ?? null) ? $baseline['results']['reads'] : []);
    $currentReads = indexReadResultsByLabel(is_array($current['results']['reads'] ?? null) ? $current['results']['reads'] : []);

    foreach ($currentReads as $label => $readResult) {
        if (! isset($baselineReads[$label])) {
            printLine(sprintf('  %s: no matching baseline scenario', $label));

            continue;
        }

        printMetricComparison(sprintf('  %s', $label), $baselineReads[$label], $readResult);
    }

    foreach (array_diff(array_keys($baselineReads), array_keys($currentReads)) as $label) {
        printLine(sprintf('  %s: present in baseline only', $label));
    }
}

/**
 * @param array<string, mixed> $baseline
 * @param array<string, mixed> $current
 *
 * @return list<string>
 */
function benchmarkConfigDifferences(array $baseline, array $current): array
{
    $differences = [];
    foreach ([
        'seedEvents',
        'seedBatchSize',
        'writeOperations',
        'writeBatchSize',
        'writeStreamCount',
        'readIterations',
        'readWarmupIterations',
    ] as $key) {
        if (($baseline[$key] ?? null) === ($current[$key] ?? null)) {
            continue;
        }

        $differences[] = sprintf('%s %s -> %s', $key, (string) ($baseline[$key] ?? 'n/a'), (string) ($current[$key] ?? 'n/a'));
    }

    return $differences;
}

/**
 * @param array<string, mixed> $baseline
 * @param array<string, mixed> $current
 *
 * @return list<string>
 */
function benchmarkSeedDifferences(array $baseline, array $current): array
{
    $differences = [];

    foreach ([
        'facultyCount',
        'courseCount',
    ] as $key) {
        if (($baseline[$key] ?? null) !== ($current[$key] ?? null)) {
            $differences[] = sprintf('%s %s -> %s', $key, (string) ($baseline[$key] ?? 'n/a'), (string) ($current[$key] ?? 'n/a'));
        }
    }

    foreach ([
        'courseCountsByBand',
        'eventCounts',
    ] as $group) {
        $baselineGroup = is_array($baseline[$group] ?? null) ? $baseline[$group] : [];
        $currentGroup = is_array($current[$group] ?? null) ? $current[$group] : [];
        foreach (array_unique([...array_keys($baselineGroup), ...array_keys($currentGroup)]) as $key) {
            if (($baselineGroup[$key] ?? null) === ($currentGroup[$key] ?? null)) {
                continue;
            }

            $differences[] = sprintf(
                '%s.%s %s -> %s',
                $group,
                (string) $key,
                (string) ($baselineGroup[$key] ?? 'n/a'),
                (string) ($currentGroup[$key] ?? 'n/a'),
            );
        }
    }

    return $differences;
}

/**
 * @param list<mixed> $readResults
 *
 * @return array<string, array<string, mixed>>
 */
function indexReadResultsByLabel(array $readResults): array
{
    $indexed = [];
    foreach ($readResults as $entry) {
        if (! is_array($entry) || ! isset($entry['label']) || ! is_string($entry['label']) || ! is_array($entry['result'] ?? null)) {
            continue;
        }

        $indexed[$entry['label']] = $entry['result'];
    }

    return $indexed;
}

/**
 * @param array<string, mixed> $baseline
 * @param array<string, mixed> $current
 */
function printMetricComparison(string $label, array $baseline, array $current): void
{
    printLine($label);

    if (($baseline['eventsPerOperation'] ?? null) !== ($current['eventsPerOperation'] ?? null)) {
        printLine(sprintf(
            '    Events per operation: %s -> %s',
            formatInteger((int) ($baseline['eventsPerOperation'] ?? 0)),
            formatInteger((int) ($current['eventsPerOperation'] ?? 0)),
        ));
    }

    printLine(sprintf(
        '    Ops/s: %s -> %s (%s)',
        formatRate((float) ($baseline['opsPerSecond'] ?? 0.0)),
        formatRate((float) ($current['opsPerSecond'] ?? 0.0)),
        formatDelta((float) ($baseline['opsPerSecond'] ?? 0.0), (float) ($current['opsPerSecond'] ?? 0.0), true),
    ));
    printLine(sprintf(
        '    Events/s: %s -> %s (%s)',
        formatRate((float) ($baseline['eventsPerSecond'] ?? 0.0)),
        formatRate((float) ($current['eventsPerSecond'] ?? 0.0)),
        formatDelta((float) ($baseline['eventsPerSecond'] ?? 0.0), (float) ($current['eventsPerSecond'] ?? 0.0), true),
    ));

    $baselineLatency = is_array($baseline['latencyMs'] ?? null) ? $baseline['latencyMs'] : [];
    $currentLatency = is_array($current['latencyMs'] ?? null) ? $current['latencyMs'] : [];

    printLine(sprintf(
        '    Avg latency: %s -> %s (%s)',
        formatMilliseconds((float) ($baselineLatency['avg'] ?? 0.0)),
        formatMilliseconds((float) ($currentLatency['avg'] ?? 0.0)),
        formatDelta((float) ($baselineLatency['avg'] ?? 0.0), (float) ($currentLatency['avg'] ?? 0.0), false),
    ));
    printLine(sprintf(
        '    P95 latency: %s -> %s (%s)',
        formatMilliseconds((float) ($baselineLatency['p95'] ?? 0.0)),
        formatMilliseconds((float) ($currentLatency['p95'] ?? 0.0)),
        formatDelta((float) ($baselineLatency['p95'] ?? 0.0), (float) ($currentLatency['p95'] ?? 0.0), false),
    ));
}

function gitHeadCommit(): string
{
    $output = shell_exec('git rev-parse --short HEAD 2>/dev/null');

    return is_string($output) && '' !== trim($output) ? trim($output) : 'unknown';
}

/**
 * @param array{
 *     dsnSource: string,
 *     saveResult: null|string,
 *     compareTo: null|string,
 *     seedEvents: int,
 *     seedBatchSize: int,
 *     writeOperations: int,
 *     writeBatchSize: int,
 *     writeStreamCount: int,
 *     readIterations: int,
 *     readWarmupIterations: int
 * } $config
 */
function printHeader(array $config, string $prefix): void
{
    printLine('Postgres Event Store Benchmark');
    printLine(str_repeat('=', 30));
    printLine(sprintf('Connection source: %s', $config['dsnSource']));
    if (null !== $config['compareTo']) {
        printLine(sprintf('Compare to: %s', $config['compareTo']));
    }
    if (null !== $config['saveResult']) {
        printLine(sprintf('Save result: %s', $config['saveResult']));
    }
    printLine(sprintf('Schema prefix: %s', $prefix));
    printLine(sprintf('Seed events: %s', formatInteger($config['seedEvents'])));
    printLine(sprintf('Seed batch size: %s', formatInteger($config['seedBatchSize'])));
    printLine(sprintf('Write operations: %s', formatInteger($config['writeOperations'])));
    printLine(sprintf('Write batch size: %s', formatInteger($config['writeBatchSize'])));
    printLine(sprintf('Write stream count: %s', formatInteger($config['writeStreamCount'])));
    printLine(sprintf('Read iterations: %s', formatInteger($config['readIterations'])));
    printLine(sprintf('Read warmup iterations: %s', formatInteger($config['readWarmupIterations'])));
    printLine();
}

/**
 * @param array{
 *     operations: int,
 *     totalSeconds: float,
 *     eventsPerSecond: float,
 *     profile: array{
 *         facultyCount: int,
 *         courseCount: int,
 *         courseCountsByBand: array{hot: int, warm: int, cold: int},
 *         eventCounts: array{course.created: int, course.capacity_changed: int, student.subscribed: int},
 *         samples: array{
 *             hotCourse: array{courseId: string, subscriptionCount: int},
 *             warmCourse: array{courseId: string, subscriptionCount: int},
 *             lifecycleCourse: array{courseId: string, eventCount: int},
 *             pairLookup: array{courseId: string, studentId: string},
 *             dashboard: array{courseIds: list<string>, eventCount: int}
 *         }
 *     }
 * } $result
 */
function printSeedResult(array $result): void
{
    printLine(sprintf(
        '  Seeded %s events in %s (%s events/s)',
        formatInteger($result['operations']),
        formatSeconds($result['totalSeconds']),
        formatRate($result['eventsPerSecond']),
    ));
    printLine(sprintf(
        '  Catalog: %s faculties | %s courses (%s hot, %s warm, %s cold)',
        formatInteger($result['profile']['facultyCount']),
        formatInteger($result['profile']['courseCount']),
        formatInteger($result['profile']['courseCountsByBand']['hot']),
        formatInteger($result['profile']['courseCountsByBand']['warm']),
        formatInteger($result['profile']['courseCountsByBand']['cold']),
    ));
    printLine(sprintf(
        '  Event mix: %s course.created | %s course.capacity_changed | %s student.subscribed',
        formatInteger($result['profile']['eventCounts']['course.created']),
        formatInteger($result['profile']['eventCounts']['course.capacity_changed']),
        formatInteger($result['profile']['eventCounts']['student.subscribed']),
    ));
    printLine(sprintf(
        '  Sample streams: hot=%s events | warm=%s events | lifecycle=%s events | dashboard=%s events',
        formatInteger($result['profile']['samples']['hotCourse']['subscriptionCount']),
        formatInteger($result['profile']['samples']['warmCourse']['subscriptionCount']),
        formatInteger($result['profile']['samples']['lifecycleCourse']['eventCount']),
        formatInteger($result['profile']['samples']['dashboard']['eventCount']),
    ));
}

/**
 * @param array{
 *     operations: int,
 *     eventsPerOperation: int,
 *     totalSeconds: float,
 *     opsPerSecond: float,
 *     eventsPerSecond: float,
 *     latencyMs: array{avg: float, min: float, p50: float, p95: float, max: float}
 * } $result
 */
function printBenchmarkResult(string $label, array $result): void
{
    printLine($label);
    printLine(sprintf('  Operations: %s', formatInteger($result['operations'])));
    printLine(sprintf('  Events per operation: %s', formatInteger($result['eventsPerOperation'])));
    printLine(sprintf('  Total time: %s', formatSeconds($result['totalSeconds'])));
    printLine(sprintf(
        '  Throughput: %s ops/s | %s events/s',
        formatRate($result['opsPerSecond']),
        formatRate($result['eventsPerSecond']),
    ));
    printLine(sprintf(
        '  Latency: avg %s | p50 %s | p95 %s | min %s | max %s',
        formatMilliseconds($result['latencyMs']['avg']),
        formatMilliseconds($result['latencyMs']['p50']),
        formatMilliseconds($result['latencyMs']['p95']),
        formatMilliseconds($result['latencyMs']['min']),
        formatMilliseconds($result['latencyMs']['max']),
    ));
}

/**
 * @param array{
 *     operations: int,
 *     eventsPerOperation: int,
 *     totalSeconds: float,
 *     opsPerSecond: float,
 *     eventsPerSecond: float,
 *     latencyMs: array{avg: float, min: float, p50: float, p95: float, max: float}
 * } $result
 */
function printReadBenchmarkResult(string $label, string $description, array $result): void
{
    printLine($label);
    printLine(sprintf('  Scenario: %s', $description));
    printLine(sprintf('  Operations: %s', formatInteger($result['operations'])));
    printLine(sprintf('  Events per operation: %s', formatInteger($result['eventsPerOperation'])));
    printLine(sprintf('  Total time: %s', formatSeconds($result['totalSeconds'])));
    printLine(sprintf(
        '  Throughput: %s ops/s | %s events/s',
        formatRate($result['opsPerSecond']),
        formatRate($result['eventsPerSecond']),
    ));
    printLine(sprintf(
        '  Latency: avg %s | p50 %s | p95 %s | min %s | max %s',
        formatMilliseconds($result['latencyMs']['avg']),
        formatMilliseconds($result['latencyMs']['p50']),
        formatMilliseconds($result['latencyMs']['p95']),
        formatMilliseconds($result['latencyMs']['min']),
        formatMilliseconds($result['latencyMs']['max']),
    ));
}

function printLine(string $message = ''): void
{
    fwrite(STDOUT, $message.PHP_EOL);
}

function formatInteger(int $value): string
{
    return number_format($value);
}

function formatSeconds(float $value): string
{
    return number_format($value, 3).' s';
}

function formatMilliseconds(float $value): string
{
    return number_format($value, 3).' ms';
}

function formatRate(float $value): string
{
    return number_format($value, 1);
}

function formatDelta(float $baseline, float $current, bool $higherIsBetter): string
{
    if (0.0 === $baseline) {
        return 0.0 === $current ? 'flat' : 'n/a';
    }

    $percent = (($current - $baseline) / $baseline) * 100;
    if (abs($percent) < 0.05) {
        return 'flat';
    }

    $improved = $higherIsBetter ? $current > $baseline : $current < $baseline;

    return sprintf('%+.1f%% %s', $percent, $improved ? 'better' : 'worse');
}
