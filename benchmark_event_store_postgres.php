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
        $readQuery = readQuery();

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
        printLine('Benchmarking reads...');
        $readResult = benchmarkReads(
            $store,
            $readQuery,
            iterations: $config['readIterations'],
            warmupIterations: $config['readWarmupIterations'],
        );
        printBenchmarkResult('Reads', $readResult);

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

    return [
        'dsn' => $dsn,
        'dsnSource' => isset($options['dsn'])
            ? '--dsn'
            : (false !== $envDsn && '' !== $envDsn ? 'ULTRA_TEST_POSTGRES_DSN' : 'docker-compose default'),
        'seedEvents' => resolvePositiveIntOption($options, 'seed-events', 20_000),
        'seedBatchSize' => resolvePositiveIntOption($options, 'seed-batch-size', 100),
        'writeOperations' => resolvePositiveIntOption($options, 'write-operations', 5_000),
        'writeBatchSize' => resolvePositiveIntOption($options, 'write-batch-size', 1),
        'writeStreamCount' => resolvePositiveIntOption($options, 'write-stream-count', 200),
        'readIterations' => resolvePositiveIntOption($options, 'read-iterations', 500),
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

function readQuery(): StreamQuery
{
    return StreamQuery::forStream(new DomainIdentifier('courseId', 'course-read-hot'))
        ->ofTypes('student.subscribed');
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
 * @return array{operations: int, eventsPerOperation: int, totalSeconds: float, opsPerSecond: float, eventsPerSecond: float}
 */
function seedReadDataset(PostgresEventStore $store, int $seedEvents, int $batchSize): array
{
    $sequence = 0;
    $startedAt = hrtime(true);

    while ($sequence < $seedEvents) {
        $events = [];

        for ($batchIndex = 0; $batchIndex < $batchSize && $sequence < $seedEvents; ++$batchIndex) {
            $events[] = studentSubscribedEvent(
                courseId: 'course-read-hot',
                facultyId: 'faculty-read',
                studentId: 'student-read-'.$sequence,
                sequence: $sequence,
            );
            ++$sequence;
        }

        $store->append($events);
    }

    $totalSeconds = elapsedSeconds($startedAt);

    return [
        'operations' => $seedEvents,
        'eventsPerOperation' => 1,
        'totalSeconds' => $totalSeconds,
        'opsPerSecond' => ratePerSecond($seedEvents, $totalSeconds),
        'eventsPerSecond' => ratePerSecond($seedEvents, $totalSeconds),
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
    $eventsPerOperation = 0;
    $startedAt = hrtime(true);

    for ($iteration = 0; $iteration < $iterations; ++$iteration) {
        $operationStartedAt = hrtime(true);
        $result = $store->load($query);
        $durationsMs[] = elapsedMilliseconds($operationStartedAt);
        $eventsPerOperation = count($result->events);
    }

    $totalSeconds = elapsedSeconds($startedAt);

    return [
        'operations' => $iterations,
        'eventsPerOperation' => $eventsPerOperation,
        'totalSeconds' => $totalSeconds,
        'opsPerSecond' => ratePerSecond($iterations, $totalSeconds),
        'eventsPerSecond' => ratePerSecond($iterations * $eventsPerOperation, $totalSeconds),
        'latencyMs' => summarizeDurations($durationsMs),
    ];
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

/**
 * @param array{
 *     dsnSource: string,
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
    printLine(sprintf('Schema prefix: %s', $prefix));
    printLine(sprintf('Seed events in hot read stream: %s', formatInteger($config['seedEvents'])));
    printLine(sprintf('Seed batch size: %s', formatInteger($config['seedBatchSize'])));
    printLine(sprintf('Write operations: %s', formatInteger($config['writeOperations'])));
    printLine(sprintf('Write batch size: %s', formatInteger($config['writeBatchSize'])));
    printLine(sprintf('Write stream count: %s', formatInteger($config['writeStreamCount'])));
    printLine(sprintf('Read iterations: %s', formatInteger($config['readIterations'])));
    printLine(sprintf('Read warmup iterations: %s', formatInteger($config['readWarmupIterations'])));
    printLine();
}

/**
 * @param array{operations: int, totalSeconds: float, eventsPerSecond: float} $result
 */
function printSeedResult(array $result): void
{
    printLine(sprintf(
        '  Seeded %s events in %s (%s events/s)',
        formatInteger($result['operations']),
        formatSeconds($result['totalSeconds']),
        formatRate($result['eventsPerSecond']),
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
