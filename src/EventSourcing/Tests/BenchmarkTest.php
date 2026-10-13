<?php

declare(strict_types=1);

namespace Ultra\EventStore\Tests;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use Ultra\EventStore\InMemoryEventStore;
use Ultra\EventStore\StreamQuery;
use Ultra\EventStore\Tests\Benchmark\CourseDataset;
use Ultra\EventStore\Tests\Benchmark\PostgresEventStoreBench;
use Ultra\EventStore\Tests\Support\PostgresStorageTestCase;

/** @internal Correctness of benchmark workloads; elapsed times are deliberately not asserted. */
#[Group('slow')]
final class BenchmarkTest extends PostgresStorageTestCase
{
    public function test_the_default_dataset_preserves_the_original_workload_and_hot_replay_exceeds_one_page(): void
    {
        $store = new InMemoryEventStore();
        $scenarios = CourseDataset::seed($store, 20_000, 100);
        static::assertSame([1245, 170, 8, 1, 28], array_column($scenarios, 'expectedEvents'));
        static::assertCount(20_000, PostgresEventStoreBench::replay($store, StreamQuery::all(), 1000)->events);
        $page = $store->load($scenarios['hot']['query']);
        static::assertCount(1000, $page->events);
        static::assertTrue($page->hasMore);
        $replay = PostgresEventStoreBench::replay($store, $scenarios['hot']['query'], 1000);
        static::assertCount(1245, $replay->events);
        static::assertFalse($replay->hasMore);
        static::assertSame($page->events, array_slice($replay->events, 0, 1000));
        static::assertSame([], $store->load($scenarios['hot']['query'], from: $replay->checkpoint)->events);
    }

    #[DataProvider('postgresVariants')]
    public function test_read_benchmarks_measure_distinct_pages_and_full_histories_and_validate_both(
        string $implementation,
        string $lockStrategy,
    ): void {
        $this->postgresDsn();
        $params = $this->parameters($implementation, $lockStrategy);
        $oracle = new InMemoryEventStore();
        $scenarios = CourseDataset::seed($oracle, $params['seedEvents'], $params['seedBatchSize']);
        $benchmark = new PostgresEventStoreBench();
        try {
            foreach ($scenarios as $scenario => $expected) {
                $pageParams = [...$params, 'scenario' => $scenario, 'readMode' => 'page'];
                $benchmark->prepareRead($pageParams);
                $page = $benchmark->benchReadPage($pageParams);
                static::assertCount(min($params['pageSize'], $expected['expectedEvents']), $page->events);
                static::assertSame($expected['expectedEvents'] > $params['pageSize'], $page->hasMore);
                $benchmark->finish($pageParams);

                $replayParams = [...$params, 'scenario' => $scenario, 'readMode' => 'replay'];
                $benchmark->prepareRead($replayParams);
                $history = $benchmark->benchReplay($replayParams);
                static::assertCount($expected['expectedEvents'], $history->events);
                static::assertFalse($history->hasMore);
                $benchmark->finish($replayParams);
            }
        } finally {
            $benchmark->close();
        }
    }

    #[DataProvider('postgresVariants')]
    public function test_the_guarded_workload_uses_complete_paginated_state_and_validates_capacity_transitions(
        string $implementation,
        string $lockStrategy,
    ): void {
        $this->withPostgresStore(
            static function ($store): void {
                CourseDataset::seedReadAppendDataset($store, 1);
                $query = CourseDataset::readAppendCourseQuery(CourseDataset::readAppendCourseId(0));
                $loaded = PostgresEventStoreBench::replay($store, $query, 2);
                static::assertSame(
                    ['capacity' => 10, 'subscriptionCount' => 4],
                    CourseDataset::courseEnrollmentState($loaded->events),
                );
                $store->append([CourseDataset::studentSubscribedEvent(
                    CourseDataset::readAppendCourseId(0),
                    CourseDataset::readAppendFacultyId(0),
                    CourseDataset::readAppendStudentId(0, 4),
                    1_000_000,
                )], $loaded->appendCondition());
                static::assertCount(7, PostgresEventStoreBench::replay($store, $query, 2)->events);
            },
            $implementation,
            $lockStrategy,
        );

        $params = [...$this->parameters($implementation, $lockStrategy), 'streamCount' => 1];
        $benchmark = new PostgresEventStoreBench();
        try {
            $benchmark->prepareGuardedAppend($params);
            for ($operation = 0; $operation < 20; ++$operation) {
                $benchmark->benchGuardedSubscription($params);
            }
            // finish compares all twenty persisted events, including confirmed -> waitlisted status changes.
            $benchmark->finish($params);
        } finally {
            $benchmark->close();
        }
    }

    /** @return iterable<string, array{string, string}> */
    public static function postgresVariants(): iterable
    {
        foreach (['hstore', 'query-streams'] as $implementation) {
            foreach (['shared-exclusive', 'bucket'] as $lockStrategy) {
                yield $implementation . '/' . $lockStrategy => [$implementation, $lockStrategy];
            }
        }
    }

    /** @return array<string, int|string> */
    private function parameters(string $implementation, string $lockStrategy): array
    {
        return [
            'datasetVersion' => 1,
            'implementation' => $implementation,
            'lockStrategy' => $lockStrategy,
            'indexState' => 'maintained',
            'seedEvents' => 1000,
            'seedBatchSize' => 100,
            'pageSize' => 7,
            'streamCount' => 3,
            'writeBatchSize' => 2,
            'driver' => 'pdo_pgsql',
        ];
    }
}
