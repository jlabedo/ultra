<?php

declare(strict_types=1);

namespace Ultra\Projection\Tests\Benchmark;

use Composer\InstalledVersions;
use Symfony\Component\Process\Process;
use Ultra\EventStore\EventStore;
use Ultra\EventStore\StreamQuery;
use Ultra\Projection\ProjectionCatalog;
use Ultra\Projection\ProjectionRunner;
use Ultra\Projection\ProjectionRunResult;
use Ultra\Projection\ProjectionRuntime;
use Ultra\Testing\PostgresConnectionSupport;
use Ultra\Testing\SqlTrace;

$options = getopt('', [
    'checkout:',
    'event-store:',
    'phase:',
    'events:',
    'append-batch:',
    'worker-batches:',
    'samples:',
    'trace',
    'help',
]);
if (isset($options['help'])) {
    echo <<<'HELP'
        Usage: php src/Projection/Tests/Benchmark/async.php [options]
          --checkout=PATH          Revision checkout with Composer dependencies installed
          --event-store=NAME       query-streams (default) or hstore
          --phase=PHASE            all (default), append or worker
          --events=N               Events per workload (default 240, minimum 4)
          --append-batch=N         Events per append (default 16)
          --worker-batches=LIST    Comma-separated worker batch sizes (default 1,32,128)
          --samples=N              Measured samples per workload after one warmup (default 5)
          --trace                  Count SQL executions and transactions in one untimed sample

        Set ULTRA_TEST_POSTGRES_DSN to the PostgreSQL test database.
        HELP;
    echo PHP_EOL;
    exit(0);
}

$checkout = realpath($options['checkout'] ?? dirname(__DIR__, 4));
if (false === $checkout || !is_file($checkout . '/vendor/autoload.php')) {
    throw new \InvalidArgumentException('Select a checkout with Composer dependencies installed.');
}
require $checkout . '/vendor/autoload.php';
require __DIR__ . '/AsyncProjectionEnvironment.php';
require __DIR__ . '/AsyncProjectionWorkload.php';

$runnerFile = new \ReflectionClass(ProjectionRunner::class)->getFileName();
if (!is_string($runnerFile) || !str_starts_with(realpath($runnerFile) ?: '', $checkout . '/src/')) {
    throw new \RuntimeException('Composer resolved ProjectionRunner outside the requested checkout.');
}

$implementation = $options['event-store'] ?? 'query-streams';
$phase = $options['phase'] ?? 'all';
if (
    !in_array($implementation, ['query-streams', 'hstore'], true)
    || !in_array($phase, ['all', 'append', 'worker'], true)
) {
    throw new \InvalidArgumentException('Choose query-streams or hstore and an all, append or worker phase.');
}
$eventCount = positiveInteger($options['events'] ?? 240, 'events', 4);
$appendBatch = positiveInteger($options['append-batch'] ?? 16, 'append-batch');
$workerBatches = array_unique(array_map(
    static fn(string $size): int => positiveInteger($size, 'worker-batches'),
    explode(',', $options['worker-batches'] ?? '1,32,128'),
));
$samples = positiveInteger($options['samples'] ?? 5, 'samples');
$trace = isset($options['trace']) ? new SqlTrace() : null;
$dsn = getenv('ULTRA_TEST_POSTGRES_DSN') ?: 'pdo-pgsql://ultra:ultra@127.0.0.1:55432/ultra_test';

$git = new Process(['git', 'rev-parse', 'HEAD'], $checkout);
$git->run();
$database = PostgresConnectionSupport::connection($dsn);
try {
    $environment = [
        'revision' => $git->isSuccessful() ? trim($git->getOutput()) : null,
        'php' => PHP_VERSION,
        'dbal' => InstalledVersions::getPrettyVersion('doctrine/dbal'),
        'driver' => $database->getDriver()::class,
        'postgres' => $database->fetchOne('SHOW server_version'),
        'synchronous_commit' => $database->fetchOne('SHOW synchronous_commit'),
        'fsync' => $database->fetchOne('SHOW fsync'),
        'opcache_cli' => ini_get('opcache.enable_cli'),
    ];
} finally {
    $database->close();
}

$cases = [];
if ('worker' !== $phase) {
    foreach ([
        'append-raw' => [false, true, false],
        'append-matched' => [true, true, false],
        'append-unmatched' => [true, false, false],
        'append-matched-in-transaction' => [true, true, true],
        'append-unmatched-in-transaction' => [true, false, true],
    ] as $label => [$projected, $matched, $callerTransaction]) {
        $cases[$label] = static fn(AsyncProjectionEnvironment $env, AsyncProjectionWorkload $workload): array => [
            $label => measureAppends(
                $env,
                $workload,
                $eventCount,
                $appendBatch,
                $projected,
                $matched,
                $callerTransaction,
                $trace,
            ),
        ];
    }
}
if ('append' !== $phase) {
    foreach ($workerBatches as $limit) {
        $cases['worker-' . $limit] = static fn(
            AsyncProjectionEnvironment $env,
            AsyncProjectionWorkload $workload,
        ): array => measureWorkers($env, $workload, $eventCount, $appendBatch, $limit, $trace);
    }
}

$observations = [];
$firstSample = null === $trace ? 0 : 1;
$lastSample = null === $trace ? $samples : 1;
for ($sample = $firstSample; $sample <= $lastSample; ++$sample) {
    foreach ($cases as $case) {
        $env = new AsyncProjectionEnvironment($dsn, $implementation, $trace);
        try {
            $measurements = $case($env, new AsyncProjectionWorkload($env->connection, $env->normalizer));
            if ($sample > 0) {
                foreach ($measurements as $label => $measurement) {
                    $observations[$label][] = $measurement;
                }
            }
        } finally {
            $env->close();
        }
    }
}

echo
    json_encode([
        'environment' => $environment,
        'event_store' => $implementation,
        'events' => $eventCount,
        'append_batch' => $appendBatch,
        'samples' => $lastSample,
        'warmups' => null === $trace ? 1 : 0,
        'workloads' => array_map(summarize(...), $observations),
    ], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR),
    PHP_EOL;

function positiveInteger(mixed $value, string $option, int $minimum = 1): int
{
    $number = filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => $minimum]]);
    if (false === $number) {
        throw new \InvalidArgumentException(sprintf('--%s must be an integer of at least %d.', $option, $minimum));
    }

    return $number;
}

function measureAppends(
    AsyncProjectionEnvironment $env,
    AsyncProjectionWorkload $workload,
    int $eventCount,
    int $appendBatch,
    bool $projected,
    bool $matched,
    bool $callerTransaction,
    ?SqlTrace $trace,
): array {
    $store = $projected ? $env->appendStore(new ProjectionCatalog(...$workload->runtimes)) : $env->eventStore;
    $batches = array_chunk($workload->events($eventCount, $matched), $appendBatch);
    $trace?->clear();
    $started = hrtime(true);
    appendBatches($env, $store, $batches, $callerTransaction);
    $elapsed = (hrtime(true) - $started) / 1_000_000;
    $sql = sqlCounts($trace);

    $loaded = $env->eventStore->load(StreamQuery::all(), batchSize: $eventCount + 1);
    if ($eventCount !== count($loaded->events) || $loaded->hasMore) {
        throw new \RuntimeException('The append workload did not persist its complete dataset.');
    }
    assertActivity($env, $projected && $matched ? $workload->runtimes : []);
    $workload->assertUntouched();

    return ['events' => $eventCount, 'operations' => count($batches), 'elapsed_ms' => $elapsed, 'sql' => $sql];
}

/** @param list<list<\Ultra\EventStore\Event>> $batches */
function appendBatches(
    AsyncProjectionEnvironment $env,
    EventStore $store,
    array $batches,
    bool $callerTransaction = false,
): void {
    $append = $callerTransaction
        ? static function (array $events) use ($env, $store): void {
            $env->connection->transactional(static function () use ($store, $events): void {
                $store->append($events);
            });
        }
        : $store->append(...);
    foreach ($batches as $events) {
        $append($events);
    }
}

function measureWorkers(
    AsyncProjectionEnvironment $env,
    AsyncProjectionWorkload $workload,
    int $eventCount,
    int $appendBatch,
    int $limit,
    ?SqlTrace $trace,
): array {
    foreach ($workload->runtimes as $runtime) {
        $env->runner->ensureInitialized($runtime);
    }
    $store = $env->appendStore(new ProjectionCatalog(...$workload->runtimes));
    appendBatches($env, $store, array_chunk($workload->events($eventCount), $appendBatch));
    $workload->assertUntouched();
    assertActivity($env, $workload->runtimes);

    $results = [];
    foreach ($workload->runtimes as $runtime) {
        $projectionId = $runtime->projectionId();
        $operations = 0;
        $processed = 0;
        $caughtUp = false;
        $trace?->clear();
        $started = hrtime(true);
        foreach (workerBatches($env->runner, $runtime, $projectionId, $limit) as $result) {
            ++$operations;
            $processed += $result->processedEvents;
            if (!$result->claimedSegment || $operations > ($eventCount + 1) || $processed > $eventCount) {
                throw new \RuntimeException("Projection \"{$projectionId}\" did not advance through the dataset.");
            }
            $caughtUp = $result->caughtUp;
        }
        $elapsed = (hrtime(true) - $started) / 1_000_000;
        if (!$caughtUp) {
            throw new \RuntimeException("Projection \"{$projectionId}\" stopped before catching up.");
        }

        $results["worker-{$limit}/{$projectionId}"] = [
            'events' => $processed,
            'operations' => $operations,
            'elapsed_ms' => $elapsed,
            'sql' => sqlCounts($trace),
        ];
    }
    $workload->assertReadModels($eventCount);
    assertActivity($env, []);

    return $results;
}

/** @return \Generator<int, ProjectionRunResult> */
function workerBatches(
    ProjectionRunner $runner,
    ProjectionRuntime $runtime,
    string $projectionId,
    int $limit,
): \Generator {
    if (method_exists($runner, 'runClaimedBatches')) {
        yield from $runner->runClaimedBatches($runtime, $projectionId, 0, 'benchmark-worker', $limit);

        return;
    }

    do {
        $result = $runner->runClaimedOnce($runtime, $projectionId, 0, 'benchmark-worker', $limit);
        yield $result;
    } while ($result->claimedSegment && !$result->caughtUp);
}

/** @param list<ProjectionRuntime> $runtimes */
function assertActivity(AsyncProjectionEnvironment $env, array $runtimes): void
{
    $expected = array_map(static fn($runtime): string => $runtime->projectionId(), $runtimes);
    $actual = array_values(array_unique(array_column($env->activity->affected(PHP_INT_MAX), 'channel')));
    sort($expected, SORT_STRING);
    sort($actual, SORT_STRING);
    if ($expected !== $actual) {
        throw new \RuntimeException('The pending activity does not match the expected projections.');
    }
}

function sqlCounts(?SqlTrace $trace): ?array
{
    if (null === $trace) {
        return null;
    }

    $counts = ['statements' => 0, 'begin' => 0, 'commit' => 0, 'rollback' => 0, 'state_reads' => 0, 'barriers' => 0];
    foreach ($trace->entries() as $entry) {
        if (in_array($entry->operation, ['begin', 'commit', 'rollback'], true)) {
            ++$counts[$entry->operation];
        }
        if (null === $entry->sql || !in_array($entry->operation, ['execute', 'query', 'exec'], true)) {
            continue;
        }

        ++$counts['statements'];
        if (1 === preg_match('/\bFROM\s+[\w.]*_states\b/i', $entry->sql)) {
            ++$counts['state_reads'];
        }
        if (
            str_contains($entry->sql, 'pg_advisory_xact_lock')
            && array_any(
                $entry->parameters,
                static fn(mixed $value): bool => is_string($value) && str_starts_with($value, 'ultra.projection:'),
            )
        ) {
            ++$counts['barriers'];
        }
    }

    return $counts;
}

function summarize(array $samples): array
{
    $first = $samples[0];
    $result = ['events' => $first['events'], 'operations' => $first['operations']];
    if (null !== $first['sql']) {
        return $result + ['sql' => $first['sql']];
    }

    foreach ($samples as $sample) {
        if ($sample['events'] !== $first['events'] || $sample['operations'] !== $first['operations']) {
            throw new \RuntimeException('The workload changed between samples.');
        }
    }
    $times = array_column($samples, 'elapsed_ms');
    $sorted = $times;
    sort($sorted, SORT_NUMERIC);
    $middle = intdiv(count($sorted), 2);
    $median = count($sorted) % 2 ? $sorted[$middle] : ($sorted[$middle - 1] + $sorted[$middle]) / 2;

    return $result + ['median_ms' => $median, 'samples_ms' => $times];
}
