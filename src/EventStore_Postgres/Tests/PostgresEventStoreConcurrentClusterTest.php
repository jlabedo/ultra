<?php

declare(strict_types=1);

namespace Ultra\EventStore_Postgres\Tests;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use Ultra\EventStore\DomainIdentifier;
use Ultra\EventStore\Event;
use Ultra\EventStore\Exception\ConcurrencyException;
use Ultra\EventStore\StreamQuery;
use Ultra\EventStore_Postgres\Tests\Support\PostgresEventStoreTestCase;

use function Ultra\EventStore_Postgres\Tests\Support\postgresTestConnection;
use function Ultra\EventStore_Postgres\Tests\Support\postgresTestSchemaForImplementation;
use function Ultra\EventStore_Postgres\Tests\Support\postgresTestStoreForImplementation;
use function Ultra\EventStore_Postgres\Tests\Support\postgresTestSupportedImplementations;
use function Ultra\EventStore_Postgres\Tests\Support\postgresTestSupportedLockStrategies;
use function Ultra\EventStore_Postgres\Tests\Support\postgresTestUniquePrefix;

use Ultra\Testing\Concurrent\Cluster;
use Ultra\Testing\Concurrent\Outcome;

require_once __DIR__.'/Support/PostgresTestSupport.php';

/**
 * End-to-end concurrency smoke test using the Cluster framework.
 *
 * Two workers load the same stream at version N and race to append.
 * Exactly one must succeed; the other must hit a ConcurrencyException.
 *
 * @internal
 */
#[CoversNothing]
#[Group('slow')]
#[Group('event-store-concurrency')]
#[Group('concurrent-testing')]
final class PostgresEventStoreConcurrentClusterTest extends PostgresEventStoreTestCase
{
    #[DataProvider('implementationAndLockStrategyProvider')]
    public function test_two_workers_race_to_append_exactly_one_succeeds(
        string $implementation,
        string $lockStrategy,
    ): void {
        $dsn = $this->postgresDsn();
        $prefix = postgresTestUniquePrefix();
        $schema = postgresTestSchemaForImplementation($prefix, $implementation, $lockStrategy);
        $connection = postgresTestConnection($dsn);

        try {
            foreach ($schema->createStatements() as $statement) {
                $connection->executeStatement($statement);
            }

            $query = StreamQuery::forStream(
                new DomainIdentifier('courseId', 'course-abc'),
                new DomainIdentifier('studentId', 'student-1'),
            )->ofTypes('student.subscribed');

            $event = new Event(
                eventType: 'student.subscribed',
                payload: ['slot' => 1],
                domainIds: ['facultyId' => 'faculty-1', 'courseId' => 'course-abc', 'studentId' => 'student-1'],
            );

            // Append one event so both workers load a non-empty append condition.
            $store = postgresTestStoreForImplementation($connection, $prefix, $implementation, lockStrategy: $lockStrategy);
            $store->append([$event]);

            $cluster = new Cluster(timeout: 10.0);

            // Absolute path to the test-support functions file, captured as a plain string
            // so the worker subprocess can require_once it after loading the autoloader.
            // Namespaced standalone functions are not autoloaded by Composer.
            $supportFile = __DIR__.'/Support/PostgresTestSupport.php';

            $makeWorker = static function () use ($dsn, $prefix, $implementation, $lockStrategy, $query, $event, $supportFile): string {
                require_once $supportFile;

                // Each worker opens its own connection — connections are not fork-safe.
                $workerConnection = \Ultra\EventStore_Postgres\Tests\Support\postgresTestConnection($dsn);
                $workerStore = \Ultra\EventStore_Postgres\Tests\Support\postgresTestStoreForImplementation($workerConnection, $prefix, $implementation, lockStrategy: $lockStrategy);

                $appendCondition = $workerStore->load($query)->appendCondition();

                checkpoint('loaded');

                try {
                    $workerStore->append([$event], $appendCondition);

                    return 'appended';
                } catch (ConcurrencyException) {
                    return 'concurrency';
                } finally {
                    $workerConnection->close();
                }
            };

            $w1 = $cluster->spawn($makeWorker);
            $w2 = $cluster->spawn($makeWorker);

            $w1->awaitCheckpoint('loaded');
            $w2->awaitCheckpoint('loaded');

            // Both have read the same stream version — now race on append.
            $cluster->releaseAll('loaded');

            $outcomes = $cluster->joinAll();

            $appended = array_filter($outcomes, static fn (Outcome $o): bool => $o->isSuccess() && 'appended' === $o->value());
            $conflict = array_filter($outcomes, static fn (Outcome $o): bool => $o->isSuccess() && 'concurrency' === $o->value());

            self::assertCount(1, $appended, sprintf(
                'Expected exactly one worker to append. Outcomes: %s',
                implode(', ', array_map(
                    static fn (Outcome $o): string => $o->isSuccess() ? (string) $o->value() : $o->exceptionMessage(),
                    $outcomes,
                )),
            ));
            self::assertCount(1, $conflict, 'Expected exactly one worker to get a ConcurrencyException.');
        } finally {
            foreach ($schema->dropStatements() as $statement) {
                $connection->executeStatement($statement);
            }
            $connection->close();
        }
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function implementationAndLockStrategyProvider(): iterable
    {
        foreach (postgresTestSupportedImplementations() as $implementation) {
            foreach (postgresTestSupportedLockStrategies() as $lockStrategy) {
                yield sprintf('%s/%s', $implementation, $lockStrategy) => [$implementation, $lockStrategy];
            }
        }
    }
}
