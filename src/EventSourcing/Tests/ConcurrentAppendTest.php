<?php

declare(strict_types=1);

namespace Ultra\EventStore\Tests;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception\DriverException;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use Ultra\EventStore\DomainIdentifier;
use Ultra\EventStore\Event;
use Ultra\EventStore\EventStore;
use Ultra\EventStore\Exception\ConcurrencyException;
use Ultra\EventStore\StreamQuery;
use Ultra\EventStore\Tests\Support\PostgresStorageTestCase;
use Ultra\Testing\Concurrent\Cluster;
use Ultra\Testing\Concurrent\Outcome;
use Ultra\Testing\Concurrent\Worker;
use Ultra\Testing\PostgresConnectionSupport;
use Ultra\Testing\PostgresTestSupport;

/**
 * @internal
 */
#[CoversNothing]
#[Group('slow')]
#[Group('event-store-concurrency')]
final class ConcurrentAppendTest extends PostgresStorageTestCase
{
    #[DataProvider('bucketCompatibilityCases')]
    public function test_bucket_compatibility_is_preserved_until_the_holder_rolls_back(
        string $implementation,
        string $heldMode,
        string $requestedMode,
        bool $compatible,
    ): void {
        $this->withPostgresStore(
            function (EventStore $store, Connection $connection, string $prefix) use (
                $implementation,
                $heldMode,
                $requestedMode,
                $compatible,
            ): void {
                $other = PostgresConnectionSupport::connection($this->postgresDsn());
                $otherStore = PostgresTestSupport::storeForImplementation(
                    $other,
                    $prefix,
                    $implementation,
                    lockStrategy: 'bucket',
                );
                $query = StreamQuery::forStream(new DomainIdentifier('courseId', 'course-123'))->ofTypes('subscribed');
                $append = static function (EventStore $target, string $mode, string $actor) use ($query): void {
                    $conditions = 'write' === $mode ? [] : [$target->load($query)->appendCondition()];
                    $event = 'read' === $mode
                        ? new Event('decided', [], ['decisionId' => $actor])
                        : new Event('subscribed', [], ['courseId' => 'course-123']);
                    $target->append([$event], ...$conditions);
                };

                try {
                    $connection->beginTransaction();
                    $append($store, $heldMode, 'holder');
                    $other->beginTransaction();
                    $other->executeStatement("SET LOCAL lock_timeout = '10ms'");
                    $other->executeStatement("SET LOCAL statement_timeout = '1s'");
                    $blocked = false;
                    try {
                        $append($otherStore, $requestedMode, 'competitor');
                    } catch (DriverException $exception) {
                        self::assertSame('55P03', $exception->getSQLState());
                        $blocked = true;
                    } finally {
                        $other->rollBack();
                    }
                    self::assertSame(!$compatible, $blocked);
                    self::assertSame(
                        0,
                        (int) $other->fetchOne(
                            "SELECT count(*) FROM pg_locks WHERE pid = pg_backend_pid() AND locktype = 'advisory'",
                        ),
                    );

                    $connection->rollBack();
                    $append($otherStore, $requestedMode, 'competitor');
                    self::assertCount(1, $store->load(StreamQuery::all())->events);
                } finally {
                    PostgresConnectionSupport::resetSharedConnection($other);
                    $other->close();
                }
            },
            $implementation,
            'bucket',
        );
    }

    /** @return iterable<string, array{string, string, string, bool}> */
    public static function bucketCompatibilityCases(): iterable
    {
        foreach (PostgresTestSupport::supportedImplementations() as $implementation) {
            foreach (['read', 'write', 'both'] as $held) {
                foreach (['read', 'write', 'both'] as $requested) {
                    yield $implementation . '/' . $held . '/' . $requested => [
                        $implementation,
                        $held,
                        $requested,
                        $held === $requested && 'both' !== $held,
                    ];
                }
            }
        }
    }

    #[DataProvider('implementationAndLockStrategyProvider')]
    public function test_concurrent_append_from_another_process_waits_for_the_lock_and_then_fails_with_concurrency_exception(
        string $implementation,
        string $lockStrategy,
    ): void {
        $dsn = $this->postgresDsn();

        $this->withPostgresStore(
            function (EventStore $store, $_connection, string $prefix) use (
                $dsn,
                $implementation,
                $lockStrategy,
            ): void {
                $query = StreamQuery::forStream(
                    new DomainIdentifier('courseId', 'course-123'),
                    new DomainIdentifier('studentId', 'student-1'),
                )->ofTypes('student.subscribed');
                $event = new Event(
                    eventType: 'student.subscribed',
                    payload: [],
                    domainIds: ['facultyId' => 'faculty-1', 'courseId' => 'course-123', 'studentId' => 'student-1'],
                );

                $store->append([$event]);

                $cluster = new Cluster(timeout: 10.0);

                $wA = $this->spawnHoldThenCommitWorker(
                    $cluster,
                    $dsn,
                    $prefix,
                    $implementation,
                    $lockStrategy,
                    $event,
                    $query,
                );
                $wB = $this->spawnAppendOnceWorker(
                    $cluster,
                    $dsn,
                    $prefix,
                    $implementation,
                    $lockStrategy,
                    $event,
                    $query,
                    lockTimeoutMs: 5_000,
                );

                $wA->awaitCheckpoint('ready');

                // B signals it is about to contend for the lock; release immediately so it proceeds.
                $wB->awaitCheckpoint('attempting');
                $wB->release('attempting');

                usleep(250_000); // let B actually block on the DB lock before we release A

                self::assertNull($wB->outcome(), 'Worker B should still be blocked on the database lock');

                $wA->release('ready'); // A commits, releasing the lock

                $wAOutcome = $wA->join(5.0);
                $wBOutcome = $wB->join(5.0);

                self::assertSame('ok', $wAOutcome->value(), $wA->stderr());
                self::assertSame('concurrency_exception', $wBOutcome->value(), $wB->stderr());
                self::assertCount(2, $store->load($query)->events);
            },
            $implementation,
            $lockStrategy,
        );
    }

    #[DataProvider('implementationAndLockStrategyProvider')]
    public function test_bucket_lock_allows_concurrent_write_for_same_course_and_different_student_pair(
        string $implementation,
        string $lockStrategy,
    ): void {
        $dsn = $this->postgresDsn();

        $this->withPostgresStore(
            function (EventStore $store, $_connection, string $prefix) use (
                $dsn,
                $implementation,
                $lockStrategy,
            ): void {
                $studentOneQuery = StreamQuery::forStream(
                    new DomainIdentifier('courseId', 'course-123'),
                    new DomainIdentifier('studentId', 'student-1'),
                )->ofTypes('student.subscribed');
                $studentTwoQuery = StreamQuery::forStream(
                    new DomainIdentifier('courseId', 'course-123'),
                    new DomainIdentifier('studentId', 'student-2'),
                )->ofTypes('student.subscribed');

                $studentOneEvent = new Event(
                    eventType: 'student.subscribed',
                    payload: [],
                    domainIds: ['facultyId' => 'faculty-1', 'courseId' => 'course-123', 'studentId' => 'student-1'],
                );
                $studentTwoEvent = new Event(
                    eventType: 'student.subscribed',
                    payload: [],
                    domainIds: ['facultyId' => 'faculty-1', 'courseId' => 'course-123', 'studentId' => 'student-2'],
                );

                $store->append([$studentOneEvent]);

                $cluster = new Cluster(timeout: 10.0);

                $wA = $this->spawnHoldThenCommitWorker(
                    $cluster,
                    $dsn,
                    $prefix,
                    $implementation,
                    $lockStrategy,
                    $studentOneEvent,
                    $studentOneQuery,
                );
                $wB = $this->spawnAppendOnceWorker(
                    $cluster,
                    $dsn,
                    $prefix,
                    $implementation,
                    $lockStrategy,
                    $studentTwoEvent,
                    lockTimeoutMs: 5_000,
                );

                $wA->awaitCheckpoint('ready');

                $wB->awaitCheckpoint('attempting');
                $wB->release('attempting');

                if ('bucket' === $lockStrategy) {
                    // Bucket lock does not contend across different student pairs — B finishes before A commits.
                    $wBOutcome = $wB->join(5.0);
                    self::assertSame('ok', $wBOutcome->value(), $wB->stderr());
                } else {
                    usleep(250_000); // let B block on the DB lock
                    self::assertNull($wB->outcome(), 'Worker B should still be blocked on the database lock');
                }

                $wA->release('ready');

                $wAOutcome = $wA->join(5.0);
                self::assertSame('ok', $wAOutcome->value(), $wA->stderr());

                if ('bucket' !== $lockStrategy) {
                    $wBOutcome = $wB->join(5.0);
                    self::assertSame('ok', $wBOutcome->value(), $wB->stderr());
                }

                self::assertCount(2, $store->load($studentOneQuery)->events);
                self::assertCount(1, $store->load($studentTwoQuery)->events);
            },
            $implementation,
            $lockStrategy,
        );
    }

    #[DataProvider('implementationAndLockStrategyProvider')]
    public function test_implementation_rejects_a_stale_multi_branch_append_condition_when_a_lower_sequence_becomes_visible_later(
        string $implementation,
        string $lockStrategy,
    ): void {
        $dsn = $this->postgresDsn();

        $this->withPostgresStore(
            function (EventStore $store, $_connection, string $prefix) use (
                $dsn,
                $implementation,
                $lockStrategy,
            ): void {
                $courseQuery = StreamQuery::forStream(new DomainIdentifier('courseId', 'course-123'))->ofTypes(
                    'course.created',
                );
                $studentQuery = StreamQuery::forStream(
                    new DomainIdentifier('courseId', 'course-456'),
                    new DomainIdentifier('studentId', 'student-2'),
                )->ofTypes('student.subscribed');
                $combinedQuery = StreamQuery::anyOf($courseQuery, $studentQuery);

                $courseCreatedEvent = new Event(
                    eventType: 'course.created',
                    payload: ['name' => 'Math 101'],
                    domainIds: ['courseId' => 'course-123'],
                );
                $studentSubscribedEvent = new Event(
                    eventType: 'student.subscribed',
                    payload: ['slot' => 2],
                    domainIds: ['facultyId' => 'faculty-1', 'courseId' => 'course-456', 'studentId' => 'student-2'],
                );
                $followUpEvent = new Event(
                    eventType: 'student.subscribed',
                    payload: ['slot' => 3],
                    domainIds: ['facultyId' => 'faculty-1', 'courseId' => 'course-456', 'studentId' => 'student-2'],
                );

                $cluster = new Cluster(timeout: 10.0);

                // A holds an open transaction (no append condition — first event in that stream).
                $wA = $this->spawnHoldThenCommitWorker(
                    $cluster,
                    $dsn,
                    $prefix,
                    $implementation,
                    $lockStrategy,
                    $courseCreatedEvent,
                );

                // B appends to a completely different stream — no lock contention with A.
                $wB = $this->spawnAppendOnceWorker(
                    $cluster,
                    $dsn,
                    $prefix,
                    $implementation,
                    $lockStrategy,
                    $studentSubscribedEvent,
                );

                $wA->awaitCheckpoint('ready');

                $wB->awaitCheckpoint('attempting');
                $wB->release('attempting');

                // B commits independently while A is still holding its transaction open.
                $wBOutcome = $wB->join(5.0);
                self::assertSame('ok', $wBOutcome->value(), $wB->stderr());

                // Load the combined query now: A's event is invisible (uncommitted), so we see only B's.
                $staleResult = $store->load($combinedQuery);
                self::assertCount(1, $staleResult->events);

                // Release A — its event commits with a lower sequence number than B's.
                $wA->release('ready');
                $wAOutcome = $wA->join(5.0);
                self::assertSame('ok', $wAOutcome->value(), $wA->stderr());

                // The stale append condition should now fail because A's event appeared "below" the checkpoint.
                try {
                    $store->append([$followUpEvent], $staleResult->appendCondition());
                    self::fail('Expected ConcurrencyException to be thrown.');
                } catch (ConcurrencyException) {
                }

                self::assertCount(2, $store->load($combinedQuery)->events);
            },
            $implementation,
            $lockStrategy,
        );
    }

    #[DataProvider('implementationAndLockStrategyProvider')]
    public function test_two_workers_race_to_append_exactly_one_succeeds(
        string $implementation,
        string $lockStrategy,
    ): void {
        $dsn = $this->postgresDsn();
        $this->withPostgresStore(
            static function (EventStore $store, $_connection, string $prefix) use (
                $dsn,
                $implementation,
                $lockStrategy,
            ): void {
                $query = StreamQuery::forStream(
                    new DomainIdentifier('courseId', 'course-abc'),
                    new DomainIdentifier('studentId', 'student-1'),
                )->ofTypes('student.subscribed');
                $event = new Event(
                    eventType: 'student.subscribed',
                    payload: ['slot' => 1],
                    domainIds: ['facultyId' => 'faculty-1', 'courseId' => 'course-abc', 'studentId' => 'student-1'],
                );
                $store->append([$event]);
                $cluster = new Cluster(timeout: 10.0);
                $makeWorker = static function () use (
                    $dsn,
                    $prefix,
                    $implementation,
                    $lockStrategy,
                    $query,
                    $event,
                ): string {
                    $connection = PostgresConnectionSupport::connection($dsn);
                    $workerStore = PostgresTestSupport::storeForImplementation(
                        $connection,
                        $prefix,
                        $implementation,
                        lockStrategy: $lockStrategy,
                    );
                    $condition = $workerStore->load($query)->appendCondition();
                    checkpoint('loaded');
                    try {
                        $workerStore->append([$event], $condition);
                        return 'appended';
                    } catch (ConcurrencyException) {
                        return 'concurrency';
                    } finally {
                        $connection->close();
                    }
                };
                $first = $cluster->spawn($makeWorker);
                $second = $cluster->spawn($makeWorker);
                $first->awaitCheckpoint('loaded');
                $second->awaitCheckpoint('loaded');
                $cluster->releaseAll('loaded');
                $outcomes = $cluster->joinAll();
                $diagnostic = implode(', ', array_map(static fn(Outcome $outcome): string => $outcome->isSuccess()
                    ? (string) $outcome->value()
                    : $outcome->exceptionMessage(), $outcomes));

                self::assertCount(
                    1,
                    array_filter(
                        $outcomes,
                        static fn(Outcome $outcome): bool => $outcome->isSuccess() && 'appended' === $outcome->value(),
                    ),
                    $diagnostic,
                );
                self::assertCount(
                    1,
                    array_filter(
                        $outcomes,
                        static fn(Outcome $outcome): bool => (
                            $outcome->isSuccess()
                            && 'concurrency' === $outcome->value()
                        ),
                    ),
                    $diagnostic,
                );
                self::assertCount(2, $store->load($query)->events);
            },
            $implementation,
            $lockStrategy,
        );
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function implementationAndLockStrategyProvider(): iterable
    {
        foreach (PostgresTestSupport::supportedImplementations() as $implementation) {
            foreach (PostgresTestSupport::supportedLockStrategies() as $lockStrategy) {
                yield sprintf('%s/%s', $implementation, $lockStrategy) => [$implementation, $lockStrategy];
            }
        }
    }

    /**
     * Spawns a worker that opens a database transaction, appends $event (optionally
     * with an append condition loaded from $appendQuery), then freezes at checkpoint
     * 'ready' while holding the transaction open. When released, it commits and returns 'ok'.
     */
    private function spawnHoldThenCommitWorker(
        Cluster $cluster,
        string $dsn,
        string $prefix,
        string $implementation,
        string $lockStrategy,
        Event $event,
        ?StreamQuery $appendQuery = null,
    ): Worker {
        return $cluster->spawn(static function () use (
            $dsn,
            $prefix,
            $implementation,
            $lockStrategy,
            $event,
            $appendQuery,
        ): string {
            $connection = PostgresConnectionSupport::connection($dsn);
            $store = PostgresTestSupport::storeForImplementation(
                $connection,
                $prefix,
                $implementation,
                lockStrategy: $lockStrategy,
            );

            $appendConditions = [];
            if (null !== $appendQuery) {
                $appendConditions = [$store->load($appendQuery)->appendCondition()];
            }

            $connection->beginTransaction();
            $store->append([$event], ...$appendConditions);

            checkpoint('ready'); // frozen here — holds the DB lock / open transaction

            $connection->commit();
            $connection->close();

            return 'ok';
        });
    }

    /**
     * Spawns a worker that pauses at checkpoint 'attempting' (so the master can
     * verify the race setup before releasing), then appends $event. Returns 'ok' on
     * success or 'concurrency_exception' if the store raises ConcurrencyException.
     */
    private function spawnAppendOnceWorker(
        Cluster $cluster,
        string $dsn,
        string $prefix,
        string $implementation,
        string $lockStrategy,
        Event $event,
        ?StreamQuery $appendQuery = null,
        ?int $lockTimeoutMs = null,
    ): Worker {
        return $cluster->spawn(static function () use (
            $dsn,
            $prefix,
            $implementation,
            $lockStrategy,
            $event,
            $appendQuery,
            $lockTimeoutMs,
        ): string {
            $connection = PostgresConnectionSupport::connection($dsn);
            $store = PostgresTestSupport::storeForImplementation(
                $connection,
                $prefix,
                $implementation,
                lockStrategy: $lockStrategy,
            );

            if (null !== $lockTimeoutMs) {
                $connection->executeStatement(sprintf("SET lock_timeout TO '%dms'", $lockTimeoutMs));
            }

            $appendConditions = [];
            if (null !== $appendQuery) {
                $appendConditions = [$store->load($appendQuery)->appendCondition()];
            }

            checkpoint('attempting'); // brief pause — master releases this immediately

            try {
                $store->append([$event], ...$appendConditions);
                $connection->close();

                return 'ok';
            } catch (ConcurrencyException) {
                if ($connection->isTransactionActive()) {
                    $connection->rollBack();
                }
                $connection->close();

                return 'concurrency_exception';
            }
        });
    }
}
