<?php

declare(strict_types=1);

namespace Ultra\EventStore\Tests\Benchmark;

use Doctrine\DBAL\Connection;
use PhpBench\Attributes as Bench;
use Ultra\EventStore\DomainIdentifier;
use Ultra\EventStore\Event;
use Ultra\EventStore\EventStore;
use Ultra\EventStore\InMemoryEventStore;
use Ultra\EventStore\LoadResult;
use Ultra\EventStore\StreamQuery;
use Ultra\EventStore_Postgres\PostgresEventStoreSchemaDefinition;
use Ultra\Testing\PostgresConnectionSupport;
use Ultra\Testing\PostgresTestSupport;

/**
 * Each PHPBench iteration owns a fresh dataset. Hooks and correctness checks are outside timed revolutions.
 * @phpstan-type Parameters array{
 *     datasetVersion: int, implementation: string, lockStrategy: string, indexState: string, driver: string,
 *     seedEvents: int, seedBatchSize: int, pageSize: int, streamCount: int, writeBatchSize: int,
 *     scenario?: string, readMode?: string
 * }
 */
#[Bench\Groups(['event-store-postgres'])]
#[Bench\AfterMethods('finish')]
final class PostgresEventStoreBench
{
    private ?Connection $connection = null;
    private PostgresEventStoreSchemaDefinition $schema;
    private string $prefix;
    private EventStore $store;
    private StreamQuery $query;
    private ?LoadResult $lastRead = null;
    private string $expectedDigest = '';
    private bool $expectedHasMore = false;
    private int $operations = 0;
    private bool $guarded = false;

    /** @param Parameters $params */
    #[Bench\ParamProviders('readPageVariants')]
    #[Bench\BeforeMethods('prepareRead')]
    #[Bench\Warmup(2)]
    public function benchReadPage(array $params): LoadResult
    {
        return $this->lastRead = $this->store->load($this->query, batchSize: $params['pageSize']);
    }

    /** @param Parameters $params */
    #[Bench\ParamProviders('replayVariants')]
    #[Bench\BeforeMethods('prepareRead')]
    #[Bench\Warmup(2)]
    public function benchReplay(array $params): LoadResult
    {
        return $this->lastRead = self::replay($this->store, $this->query, $params['pageSize']);
    }

    /** @param Parameters $params */
    #[Bench\ParamProviders('appendVariants')]
    #[Bench\BeforeMethods('prepareAppend')]
    public function benchAppend(array $params): void
    {
        $stream = $this->operations % $params['streamCount'];
        $events = [];
        for ($index = 0; $index < $params['writeBatchSize']; ++$index) {
            $events[] = CourseDataset::studentSubscribedEvent(
                sprintf('course-write-%d', $stream),
                sprintf('faculty-write-%d', $stream % 10),
                sprintf('student-write-%d-%d', $this->operations, $index),
                ($this->operations * $params['writeBatchSize']) + $index,
            );
        }
        $this->store->append($events);
        ++$this->operations;
    }

    /** @param Parameters $params */
    #[Bench\ParamProviders('variants')]
    #[Bench\BeforeMethods('prepareGuardedAppend')]
    public function benchGuardedSubscription(array $params): void
    {
        $stream = $this->operations % $params['streamCount'];
        $course = CourseDataset::readAppendCourseId($stream);
        $loaded = self::replay($this->store, CourseDataset::readAppendCourseQuery($course), $params['pageSize']);
        $state = CourseDataset::courseEnrollmentState($loaded->events);
        $this->store->append([
            CourseDataset::studentSubscribedEvent(
                $course,
                CourseDataset::readAppendFacultyId($stream),
                CourseDataset::readAppendStudentId($stream, $state['subscriptionCount']),
                1_000_000 + $this->operations,
                $state['subscriptionCount'] < $state['capacity'] ? 'confirmed' : 'waitlisted',
            ),
        ], $loaded->appendCondition());
        ++$this->operations;
    }

    /** @param Parameters $params */
    public function prepareRead(array $params): void
    {
        $this->open($params);
        try {
            $scenarios = CourseDataset::seed($this->store, $params['seedEvents'], $params['seedBatchSize']);
            $oracle = new InMemoryEventStore();
            CourseDataset::seed($oracle, $params['seedEvents'], $params['seedBatchSize']);
            $this->query = $scenarios[$params['scenario']]['query'];
            $expected = 'replay' === $params['readMode']
                ? self::replay($oracle, $this->query, $params['pageSize'])
                : $oracle->load($this->query, batchSize: $params['pageSize']);
            $this->expectedDigest = self::digest($expected->events);
            $this->expectedHasMore = $expected->hasMore;
            $this->prepareIndexes($params['indexState']);
        } catch (\Throwable $exception) {
            $this->close();
            throw $exception;
        }
    }

    /** @param Parameters $params */
    public function prepareAppend(array $params): void
    {
        $this->open($params);
        try {
            CourseDataset::seed($this->store, $params['seedEvents'], $params['seedBatchSize']);
            $this->prepareIndexes($params['indexState']);
        } catch (\Throwable $exception) {
            $this->close();
            throw $exception;
        }
    }

    /** @param Parameters $params */
    public function prepareGuardedAppend(array $params): void
    {
        $this->open($params);
        $this->guarded = true;
        try {
            CourseDataset::seed($this->store, $params['seedEvents'], $params['seedBatchSize']);
            CourseDataset::seedReadAppendDataset($this->store, $params['streamCount']);
            $this->prepareIndexes($params['indexState']);
        } catch (\Throwable $exception) {
            $this->close();
            throw $exception;
        }
    }

    /** @param Parameters $params */
    public function finish(array $params): void
    {
        try {
            if (null !== $this->lastRead) {
                if (
                    self::digest($this->lastRead->events) !== $this->expectedDigest
                    || $this->lastRead->hasMore !== $this->expectedHasMore
                ) {
                    throw new \RuntimeException('The measured read differs from the complete reference history.');
                }
            } else {
                $this->verifyAppends($params);
            }
        } finally {
            $this->close();
        }
    }

    /** Reconstruct the entire query, preserving the final checkpoint for a guarded append. */
    public static function replay(EventStore $store, StreamQuery $query, int $pageSize): LoadResult
    {
        $events = [];
        $from = null;
        do {
            $page = $store->load($query, from: $from, batchSize: $pageSize);
            array_push($events, ...$page->events);
            $from = $page->checkpoint;
        } while ($page->hasMore);

        return new LoadResult($events, $page->checkpoint, $query);
    }

    /** @return iterable<string, Parameters> */
    public function variants(): iterable
    {
        $indexState = getenv('ULTRA_BENCH_INDEX_STATE') ?: 'maintained';
        if (!in_array($indexState, ['maintained', 'post-ingest'], true)) {
            throw new \InvalidArgumentException('ULTRA_BENCH_INDEX_STATE must be maintained or post-ingest.');
        }
        foreach (PostgresTestSupport::supportedImplementations() as $implementation) {
            foreach (PostgresTestSupport::supportedLockStrategies() as $lockStrategy) {
                $params = [
                    'datasetVersion' => 1,
                    'implementation' => $implementation,
                    'lockStrategy' => $lockStrategy,
                    'indexState' => $indexState,
                    'seedEvents' => self::positiveEnvironment('ULTRA_BENCH_SEED_EVENTS', 20_000, 4),
                    'seedBatchSize' => 100,
                    'pageSize' => self::positiveEnvironment('ULTRA_BENCH_PAGE_SIZE', 1000),
                    'streamCount' => self::positiveEnvironment('ULTRA_BENCH_STREAM_COUNT', 200),
                    'writeBatchSize' => 1,
                    'driver' => PostgresConnectionSupport::connection(
                        getenv('ULTRA_TEST_POSTGRES_DSN') ?: 'pdo-pgsql://ultra:ultra@127.0.0.1:55432/ultra_test',
                    )->getParams()['driver'],
                ];
                // PHPBench matches baselines by parameter-set name, not by parameter values.
                $fingerprint = substr(hash('sha256', json_encode($params, JSON_THROW_ON_ERROR)), 0, 10);
                yield $implementation . '/' . $lockStrategy . '/cfg-' . $fingerprint => $params;
            }
        }
    }

    /** @return iterable<string, Parameters> */
    public function readPageVariants(): iterable
    {
        foreach ($this->variants() as $name => $params) {
            foreach (['hot', 'warm', 'lifecycle', 'pair', 'dashboard'] as $scenario) {
                yield $name . '/' . $scenario => [...$params, 'scenario' => $scenario, 'readMode' => 'page'];
            }
        }
    }

    /** @return iterable<string, Parameters> */
    public function replayVariants(): iterable
    {
        foreach ($this->variants() as $name => $params) {
            yield $name . '/hot' => [...$params, 'scenario' => 'hot', 'readMode' => 'replay'];
        }
    }

    /** @return iterable<string, Parameters> */
    public function appendVariants(): iterable
    {
        foreach ($this->variants() as $name => $params) {
            foreach ([1, 100] as $size) {
                yield $name . '/batch-' . $size => [...$params, 'writeBatchSize' => $size];
            }
        }
    }

    /** @param Parameters $params */
    private function open(array $params): void
    {
        $this->close();
        $this->lastRead = null;
        $this->operations = 0;
        $this->guarded = false;
        $this->prefix = 'ultra_bench_' . bin2hex(random_bytes(4)) . '_';
        $this->schema = PostgresTestSupport::schemaForImplementation(
            $this->prefix,
            $params['implementation'],
            $params['lockStrategy'],
        );
        $dsn = getenv('ULTRA_TEST_POSTGRES_DSN');
        if (false === $dsn || '' === $dsn) {
            throw new \RuntimeException('Set ULTRA_TEST_POSTGRES_DSN or use bin/phpbench.');
        }
        $this->connection = PostgresConnectionSupport::connection($dsn);
        // PHPBench skips after hooks on subject failure; shutdown still removes this iteration's tables.
        register_shutdown_function($this->close(...));
        try {
            foreach ($this->schema->createStatements() as $statement) {
                $this->connection->executeStatement($statement);
            }
            $this->store = PostgresTestSupport::storeForImplementation(
                $this->connection,
                $this->prefix,
                $params['implementation'],
                lockStrategy: $params['lockStrategy'],
            );
        } catch (\Throwable $exception) {
            $this->close();
            throw $exception;
        }
    }

    public function close(): void
    {
        if (null === $this->connection) {
            return;
        }
        $connection = $this->connection;
        $this->connection = null;
        try {
            while ($connection->isTransactionActive()) {
                $connection->rollBack();
            }
            foreach ($this->schema->dropStatements() as $statement) {
                $connection->executeStatement($statement);
            }
        } finally {
            $connection->close();
        }
    }

    private function prepareIndexes(string $state): void
    {
        $command = 'maintained' === $state ? 'VACUUM (ANALYZE)' : 'ANALYZE';
        $this->connection->executeStatement($command . ' ' . $this->prefix . 'events');
        if ('query-streams' === $this->storeImplementation()) {
            $this->connection->executeStatement($command . ' ' . $this->prefix . 'event_domain_ids');
        }
    }

    private function storeImplementation(): string
    {
        return $this->store instanceof \Ultra\EventStore_Postgres\PostgresEventStoreQueryStreams
            ? 'query-streams'
            : 'hstore';
    }

    /** @param Parameters $params */
    private function verifyAppends(array $params): void
    {
        for ($stream = 0; $stream < min($this->operations, $params['streamCount']); ++$stream) {
            $query = $this->guarded
                ? CourseDataset::readAppendCourseQuery(CourseDataset::readAppendCourseId($stream))
                : StreamQuery::forStream(new DomainIdentifier('courseId', sprintf('course-write-%d', $stream)));
            $loaded = self::replay($this->store, $query, $params['pageSize']);
            $expected = [];
            $subscriptions = 4 + ($stream % 8);
            for ($operation = $stream; $operation < $this->operations; $operation += $params['streamCount']) {
                if ($this->guarded) {
                    $expected[] = CourseDataset::studentSubscribedEvent(
                        CourseDataset::readAppendCourseId($stream),
                        CourseDataset::readAppendFacultyId($stream),
                        CourseDataset::readAppendStudentId($stream, $subscriptions),
                        1_000_000 + $operation,
                        $subscriptions < (10 + ($stream % 12)) ? 'confirmed' : 'waitlisted',
                    );
                    ++$subscriptions;
                } else {
                    for ($index = 0; $index < $params['writeBatchSize']; ++$index) {
                        $expected[] = CourseDataset::studentSubscribedEvent(
                            sprintf('course-write-%d', $stream),
                            sprintf('faculty-write-%d', $stream % 10),
                            sprintf('student-write-%d-%d', $operation, $index),
                            ($operation * $params['writeBatchSize']) + $index,
                        );
                    }
                }
            }
            $appended = $this->guarded ? array_slice($loaded->events, 2 + 4 + ($stream % 8)) : $loaded->events;
            if (self::digest($appended) !== self::digest($expected)) {
                throw new \RuntimeException('Measured appends differ from the expected enrollment history.');
            }
        }
    }

    /** @param list<Event> $events */
    private static function digest(array $events): string
    {
        return hash('sha256', json_encode(array_map(static function (Event $event): array {
            $ids = $event->domainIds;
            $payload = $event->payload;
            ksort($ids);
            ksort($payload);
            return [$event->eventType, $payload, $event->metadata, $ids];
        }, $events), JSON_THROW_ON_ERROR));
    }

    private static function positiveEnvironment(string $name, int $default, int $minimum = 1): int
    {
        $value = getenv($name);
        if (false === $value) {
            return $default;
        }
        $parsed = filter_var($value, FILTER_VALIDATE_INT);
        if (false === $parsed || $parsed < $minimum) {
            throw new \InvalidArgumentException(sprintf('%s must be an integer >= %d.', $name, $minimum));
        }
        return $parsed;
    }
}
