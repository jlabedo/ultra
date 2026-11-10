<?php

declare(strict_types=1);

namespace Ultra\Projection\Tests;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception\DriverException;
use PHPUnit\Framework\Attributes\CoversNamespace;
use Ultra\Mapper\UltraNormalizer;
use Ultra\Projection\Coordination\ProjectionActivity;
use Ultra\Projection\Coordination\ProjectionActivityRecorder;
use Ultra\Projection\InMemory\InMemoryProjectionActivator;
use Ultra\Projection\ProjectingEventStore;
use Ultra\Projection\ProjectionCatalog;
use Ultra\Projection\ProjectionMode;
use Ultra\Projection\ProjectionPhase;
use Ultra\Projection\ProjectionRebuilder;
use Ultra\Projection\ProjectionRunner;
use Ultra\Projection\ProjectionRuntime;
use Ultra\Projection\Tests\Support\ManagedBufferedModeProjection;
use Ultra\Projection\Tests\Support\PostgresProjectionTestCase;
use Ultra\Projection\Tests\Support\ProjectionModeFixture;
use Ultra\Projection_Postgres\PostgresProjectionImplementation;
use Ultra\Testing\PostgresConnectionSupport;
use Ultra\Testing\PostgresTestSupport;

require_once __DIR__ . '/Support/ProjectionModes.php';

/** @internal */
#[CoversNamespace('Ultra\\Projection')]
#[CoversNamespace('Ultra\\Projection_Postgres')]
final class ActivationConcurrencyTest extends PostgresProjectionTestCase
{
    public function test_activation_excludes_an_uncommitted_writer_and_drains_its_late_event(): void
    {
        $f = new ProjectionModeFixture($this->fixture('postgres'), ProjectionMode::Synchronous, true);
        $f->runner->ensureInitialized($f->runtime);
        $peer = PostgresConnectionSupport::sharedConnection($this->postgresDsn(), 'projection-activation');
        [$peerStore, $peerRebuilder] = $this->actor($f, $peer);
        $connection = $f->fixture->connection;
        $connection->beginTransaction();
        try {
            $f->store->append([self::courseCreatedEvent('c', 'late')]);
            $peer->executeStatement("SET lock_timeout = '20ms'");
            try {
                $peerRebuilder->activate($this->peerRuntime($f, $peer));
                static::fail('Activation must wait for the writer transaction.');
            } catch (DriverException $e) {
                static::assertSame('55P03', $e->getSQLState());
            }
            static::assertSame(ProjectionPhase::Building, $f->states->find('mode_projection')->phase);
            $connection->commit();
            $peer->executeStatement('RESET lock_timeout');
            static::assertSame(1, $peerRebuilder->activate($this->peerRuntime($f, $peer)));
            $peerStore->append([self::courseCreatedEvent('c', 'live')]);
            static::assertSame(['late', 'live'], $f->projection->names());
        } finally {
            PostgresConnectionSupport::resetSharedConnection($connection);
            PostgresConnectionSupport::resetSharedConnection($peer);
            $peer->executeStatement('RESET lock_timeout');
        }
    }

    public function test_uncommitted_activation_blocks_appends_and_rollback_restores_building(): void
    {
        $f = new ProjectionModeFixture($this->fixture('postgres'), ProjectionMode::Synchronous, true);
        $f->runner->ensureInitialized($f->runtime);
        $f->store->append([self::courseCreatedEvent('c', 'history')]);
        $peer = PostgresConnectionSupport::sharedConnection($this->postgresDsn(), 'projection-blocked-writer');
        [$peerStore] = $this->actor($f, $peer);
        $connection = $f->fixture->connection;
        $connection->beginTransaction();
        try {
            $f->rebuilder->activate($f->runtime);
            $peer->executeStatement("SET lock_timeout = '20ms'");
            try {
                $peerStore->append([self::courseCreatedEvent('c', 'blocked')]);
                static::fail('Writer must wait for activation commit.');
            } catch (DriverException $e) {
                static::assertSame('55P03', $e->getSQLState());
            }
            $connection->rollBack();
            $peer->executeStatement('RESET lock_timeout');
            static::assertSame(ProjectionPhase::Building, $f->states->find('mode_projection')->phase);
            static::assertSame([], $f->projection->names());
            $peerStore->append([self::courseCreatedEvent('c', 'after-rollback')]);
            static::assertSame([], $f->projection->names());
            static::assertSame(2, $f->rebuilder->activate($f->runtime));
            static::assertSame(['history', 'after-rollback'], $f->projection->names());
        } finally {
            PostgresConnectionSupport::resetSharedConnection($connection);
            PostgresConnectionSupport::resetSharedConnection($peer);
            $peer->executeStatement('RESET lock_timeout');
        }
    }

    public function test_worker_application_holds_the_barrier_until_its_outer_transaction_ends(): void
    {
        $f = new ProjectionModeFixture($this->fixture('postgres'), ProjectionMode::Synchronous, true);
        $f->store->append([self::courseCreatedEvent('c', 'history')]);
        $f->runner->ensureInitialized($f->runtime);
        $peer = PostgresConnectionSupport::sharedConnection($this->postgresDsn(), 'projection-worker-barrier');
        [, $peerRebuilder] = $this->actor($f, $peer);
        $connection = $f->fixture->connection;
        $connection->beginTransaction();
        try {
            $f->runner->backfillClaimedOnce($f->runtime, 'mode_projection', 0, 'worker');
            $peer->executeStatement("SET lock_timeout = '20ms'");
            try {
                $peerRebuilder->activate($this->peerRuntime($f, $peer));
                static::fail('Activation must wait for the worker transaction.');
            } catch (DriverException $e) {
                static::assertSame('55P03', $e->getSQLState());
            }
            $connection->commit();
            $peer->executeStatement('RESET lock_timeout');
            static::assertSame(0, $peerRebuilder->activate($this->peerRuntime($f, $peer)));
            static::assertSame(['history'], $f->projection->names());
        } finally {
            PostgresConnectionSupport::resetSharedConnection($connection);
            PostgresConnectionSupport::resetSharedConnection($peer);
            $peer->executeStatement('RESET lock_timeout');
        }
    }

    public function test_live_barriers_allow_independent_writers_to_remain_concurrent(): void
    {
        $f = new ProjectionModeFixture($this->fixture('postgres'), ProjectionMode::Synchronous, true);
        $f->rebuilder->activate($f->runtime);
        $peer = PostgresConnectionSupport::sharedConnection($this->postgresDsn(), 'projection-concurrent-writer');
        [$peerStore] = $this->actor($f, $peer);
        $connection = $f->fixture->connection;
        $connection->beginTransaction();
        try {
            $f->store->append([self::courseCreatedEvent('a', 'first')]);
            $peer->executeStatement("SET lock_timeout = '20ms'");
            $peerStore->append([self::courseCreatedEvent('b', 'second')]);
            $connection->commit();
            static::assertSame(['first', 'second'], $f->projection->names());
        } finally {
            PostgresConnectionSupport::resetSharedConnection($connection);
            PostgresConnectionSupport::resetSharedConnection($peer);
            $peer->executeStatement('RESET lock_timeout');
        }
    }

    public function test_activation_recovers_a_lower_sequence_committed_after_a_catch_up_page(): void
    {
        $f = new ProjectionModeFixture($this->fixture('postgres'), ProjectionMode::Synchronous, true);
        $f->runner->ensureInitialized($f->runtime);
        $peer = PostgresConnectionSupport::sharedConnection($this->postgresDsn(), 'projection-gap-reader');
        $peer->executeStatement("SET lock_timeout = '100ms'");
        [$peerStore, $peerRebuilder] = $this->actor($f, $peer);
        $connection = $f->fixture->connection;
        $connection->beginTransaction();
        try {
            $f->store->append([self::courseCreatedEvent('a', 'late-low-sequence')]);
            $peerStore->append([self::courseCreatedEvent('b', 'visible-high-sequence')]);
            static::assertSame(1, $peerRebuilder->catchUp($this->peerRuntime($f, $peer)));
            static::assertSame(['visible-high-sequence'], $f->projection->names());
            $connection->commit();
            static::assertSame(1, $f->rebuilder->activate($f->runtime));
            static::assertSame(['visible-high-sequence', 'late-low-sequence'], $f->projection->names());
            static::assertSame(0, $f->rebuilder->activate($f->runtime));
        } finally {
            PostgresConnectionSupport::resetSharedConnection($connection);
            PostgresConnectionSupport::resetSharedConnection($peer);
            $peer->executeStatement('RESET lock_timeout');
        }
    }

    public function test_registration_waits_for_an_older_writer_and_rejects_its_later_appends(): void
    {
        $f = new ProjectionModeFixture($this->fixture('postgres'), ProjectionMode::Synchronous, true);
        $peer = PostgresConnectionSupport::sharedConnection($this->postgresDsn(), 'projection-old-writer');
        $implementation = new PostgresProjectionImplementation($peer, $f->fixture->prefix);
        $oldCatalog = new ProjectionCatalog();
        $oldStore = new ProjectingEventStore(
            PostgresTestSupport::storeForImplementation($peer, $f->fixture->prefix . 'event_', 'query-streams'),
            $oldCatalog,
            new ProjectionActivityRecorder(
                $oldCatalog,
                new ProjectionActivity($implementation->createDirtyKeyTracker()),
                new \Ultra\Coordination\InMemory\InMemoryCoordinatorLease(),
                new InMemoryProjectionActivator(),
            ),
            $implementation->createTransactionManager(),
            $implementation->createStateRepository(),
        );
        $connection = $f->fixture->connection;
        $peer->beginTransaction();
        try {
            $oldStore->append([self::courseCreatedEvent('c', 'before-registration')]);
            $connection->executeStatement("SET lock_timeout = '20ms'");
            try {
                $f->runner->ensureInitialized($f->runtime);
                static::fail('Registration must wait for the old writer.');
            } catch (DriverException $e) {
                static::assertSame('55P03', $e->getSQLState());
            }
            static::assertNull($f->states->find('mode_projection'));
            $peer->commit();
            $connection->executeStatement('RESET lock_timeout');
            static::assertSame(1, $f->rebuilder->activate($f->runtime));
            try {
                $oldStore->append([self::courseCreatedEvent('c', 'rejected')]);
                static::fail('An older writer must not silently skip the projection.');
            } catch (\LogicException $e) {
                static::assertStringContainsString('missing managed projection', $e->getMessage());
            }
            static::assertSame(['before-registration'], $f->projection->names());
            static::assertCount(1, $f->fixture->store->load(\Ultra\EventStore\StreamQuery::all())->events);
        } finally {
            PostgresConnectionSupport::resetSharedConnection($connection);
            PostgresConnectionSupport::resetSharedConnection($peer);
            $connection->executeStatement('RESET lock_timeout');
        }
    }

    public function test_activation_allows_appends_that_do_not_affect_the_projection(): void
    {
        $f = new ProjectionModeFixture($this->fixture('postgres'), ProjectionMode::Synchronous, true);
        $f->runner->ensureInitialized($f->runtime);
        $peer = PostgresConnectionSupport::sharedConnection($this->postgresDsn(), 'projection-unrelated-writer');
        [$peerStore] = $this->actor($f, $peer);
        $connection = $f->fixture->connection;
        $connection->beginTransaction();
        try {
            $f->rebuilder->activate($f->runtime);
            $peer->executeStatement("SET lock_timeout = '20ms'");
            $peerStore->append([new \Ultra\EventStore\Event('unrelated', [], ['otherId' => 'u'])]);
            static::assertSame([], $f->projection->names());
            $connection->commit();
            static::assertCount(1, $f->fixture->store->load(\Ultra\EventStore\StreamQuery::all())->events);
        } finally {
            PostgresConnectionSupport::resetSharedConnection($connection);
            PostgresConnectionSupport::resetSharedConnection($peer);
            $peer->executeStatement('RESET lock_timeout');
        }
    }

    private function peerRuntime(ProjectionModeFixture $f, Connection $connection): ProjectionRuntime
    {
        return new ProjectionRuntime(
            new ManagedBufferedModeProjection($connection, $f->projection->table),
            new UltraNormalizer(),
        );
    }

    /** @return array{ProjectingEventStore, ProjectionRebuilder} */
    private function actor(ProjectionModeFixture $f, Connection $connection): array
    {
        $implementation = new PostgresProjectionImplementation($connection, $f->fixture->prefix);
        $store = PostgresTestSupport::storeForImplementation(
            $connection,
            $f->fixture->prefix . 'event_',
            'query-streams',
        );
        $runtime = $this->peerRuntime($f, $connection);
        $catalog = new ProjectionCatalog($runtime);
        $states = $implementation->createStateRepository();
        $transactions = $implementation->createTransactionManager();
        $segments = $implementation->createSegmentRepository();
        $activity = new ProjectionActivity($implementation->createDirtyKeyTracker());
        // Isolate projection barriers from the independent durable wake-debounce row.
        $recorder = new ProjectionActivityRecorder(
            $catalog,
            $activity,
            new \Ultra\Coordination\InMemory\InMemoryCoordinatorLease(),
            new InMemoryProjectionActivator(),
        );
        $runner = new ProjectionRunner($store, $segments, $transactions, $activity, $states);

        return [
            new ProjectingEventStore($store, $catalog, $recorder, $transactions, $states),
            new ProjectionRebuilder($store, $segments, $states, $transactions, $runner),
        ];
    }
}
