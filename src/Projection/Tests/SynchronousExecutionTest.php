<?php

declare(strict_types=1);

namespace Ultra\Projection\Tests;

use PHPUnit\Framework\Attributes\CoversNamespace;
use PHPUnit\Framework\Attributes\DataProvider;
use Ultra\EventStore\EventStoreUnitOfWork;
use Ultra\EventStore\StreamQuery;
use Ultra\Projection\ProjectionMode;
use Ultra\Projection\Tests\Support\ProjectionModeFixture;
use Ultra\Projection\Tests\Support\ProjectionTestCase;
use Ultra\Testing\PostgresConnectionSupport;

require_once __DIR__ . '/Support/ProjectionModes.php';

/** @internal */
#[CoversNamespace('Ultra\\Projection')]
#[CoversNamespace('Ultra\\Projection_Postgres')]
final class SynchronousExecutionTest extends ProjectionTestCase
{
    public static function immediateConfigurations(): iterable
    {
        foreach (['memory', 'postgres'] as $backend) {
            foreach ([false, true] as $applicationBuffer) {
                foreach ([false, true] as $projectionBuffer) {
                    yield "$backend/app=$applicationBuffer/projection=$projectionBuffer" => [
                        $backend,
                        $applicationBuffer,
                        $projectionBuffer,
                    ];
                }
            }
        }
    }

    #[DataProvider('immediateConfigurations')]
    public function test_direct_and_buffered_appends_share_immediate_projection_execution(
        string $backend,
        bool $applicationBuffer,
        bool $projectionBuffer,
    ): void {
        $f = new ProjectionModeFixture($this->fixture($backend), ProjectionMode::SynchronousFromNow, $projectionBuffer);
        $f->fixture->store->append([self::courseCreatedEvent('c', 'historical')]);
        $store = $applicationBuffer ? new EventStoreUnitOfWork($f->store) : $f->store;
        $store->append([self::courseCreatedEvent('c', 'one'), self::courseCreatedEvent('c', 'two')]);
        if ($applicationBuffer) {
            static::assertSame([], $f->projection->names());
            $f->fixture->transactions->transactional($store->flush(...));
            $store->clear();
        }
        static::assertSame(['one', 'two'], $f->projection->names());
        static::assertCount(3, $f->fixture->store->load(StreamQuery::all())->events);
        static::assertSame([], $f->states->all());
        static::assertSame([], $f->fixture->segments->listSegments('mode_projection'));
        static::assertSame([], $f->fixture->activity->affected());
        static::assertSame(0, $f->projection->initialized);
        if ($projectionBuffer) {
            static::assertSame([], $f->projection->pending);
        }
    }

    public static function failures(): iterable
    {
        foreach ([false, true] as $applicationBuffer) {
            foreach (['apply', 'finish', 'persistence'] as $stage) {
                foreach ([false, true] as $projectionBuffer) {
                    if (!$projectionBuffer && 'persistence' === $stage) {
                        continue;
                    }
                    yield "app=$applicationBuffer/projection=$projectionBuffer/$stage" => [
                        $applicationBuffer,
                        $stage,
                        $projectionBuffer,
                    ];
                }
            }
        }
    }

    #[DataProvider('failures')]
    public function test_projection_failures_roll_back_events_and_private_writes_and_allow_clean_retry(
        bool $applicationBuffer,
        string $stage,
        bool $projectionBuffer,
    ): void {
        $f = new ProjectionModeFixture(
            $this->fixture('postgres'),
            ProjectionMode::SynchronousFromNow,
            $projectionBuffer,
        );
        $store = $applicationBuffer ? new EventStoreUnitOfWork($f->store) : $f->store;
        $f->projection->failAt = $stage;
        try {
            if ($applicationBuffer) {
                $store->append([self::courseCreatedEvent('c', 'failed')]);
                $f->fixture->transactions->transactional($store->flush(...));
            } else {
                $store->append([self::courseCreatedEvent('c', 'failed')]);
            }
            static::fail('Expected the projection to fail.');
        } catch (\RuntimeException $e) {
            static::assertSame('Failed ' . $stage . '.', $e->getMessage());
        } finally {
            if ($applicationBuffer) {
                $store->clear();
            }
        }
        static::assertSame([], $f->projection->names());
        static::assertSame([], $f->fixture->store->load(StreamQuery::all())->events);
        if ($projectionBuffer) {
            static::assertSame([], $f->projection->pending);
        }
        static::assertFalse($f->fixture->connection->isTransactionActive());
        $f->projection->failAt = null;
        $f->store->append([self::courseCreatedEvent('c', 'retry')]);
        static::assertSame(['retry'], $f->projection->names());
    }

    public function test_append_conditions_fail_before_projection_execution(): void
    {
        $f = new ProjectionModeFixture($this->fixture('postgres'), ProjectionMode::SynchronousFromNow, true);
        $snapshot = $f->store->load(StreamQuery::forStream(new \Ultra\EventStore\DomainIdentifier('courseId', 'c')));
        $f->store->append([self::courseCreatedEvent('c', 'first')]);
        try {
            $f->store->append([self::courseCreatedEvent('c', 'conflict')], $snapshot->appendCondition());
            static::fail('Expected optimistic conflict.');
        } catch (\Ultra\EventStore\Exception\ConcurrencyException) {
            static::assertSame(['first'], $f->projection->names());
        }
        static::assertCount(1, $f->fixture->store->load(StreamQuery::all())->events);
    }

    public function test_an_outer_transaction_controls_visibility_of_events_and_projection(): void
    {
        $f = new ProjectionModeFixture($this->fixture('postgres'), ProjectionMode::SynchronousFromNow, true);
        $observer = PostgresConnectionSupport::sharedConnection(
            getenv('ULTRA_TEST_POSTGRES_DSN'),
            'projection-observer',
        );
        $connection = $f->fixture->connection;
        $connection->beginTransaction();
        try {
            $f->store->append([self::courseCreatedEvent('c', 'pending')]);
            static::assertSame(['pending'], $f->projection->names());
            static::assertSame(0, (int) $observer->fetchOne('SELECT count(*) FROM ' . $f->projection->table));
            static::assertSame(
                0,
                (int) $observer->fetchOne('SELECT count(*) FROM ' . $f->fixture->prefix . 'event_events'),
            );
        } finally {
            $connection->rollBack();
        }
        static::assertSame([], $f->projection->names());
        static::assertSame([], $f->fixture->store->load(StreamQuery::all())->events);
    }

    public function test_catching_a_projection_failure_cannot_commit_the_partial_append(): void
    {
        $f = new ProjectionModeFixture($this->fixture('postgres'), ProjectionMode::SynchronousFromNow, true);
        $connection = $f->fixture->connection;
        $connection->beginTransaction();
        $f->projection->failAt = 'apply';
        try {
            try {
                $f->store->append([self::courseCreatedEvent('c', 'bad')]);
            } catch (\RuntimeException) {
                static::assertTrue($connection->isRollbackOnly());
            }
        } finally {
            $connection->rollBack();
        }
        static::assertSame([], $f->fixture->store->load(StreamQuery::all())->events);
    }
}
