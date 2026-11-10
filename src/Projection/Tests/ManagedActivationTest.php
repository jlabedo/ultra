<?php

declare(strict_types=1);

namespace Ultra\Projection\Tests;

use PHPUnit\Framework\Attributes\CoversNamespace;
use PHPUnit\Framework\Attributes\DataProvider;
use Ultra\EventStore\BucketHasher;
use Ultra\EventStore\EventStoreUnitOfWork;
use Ultra\EventStore\StreamQuery;
use Ultra\Mapper\UltraNormalizer;
use Ultra\Projection\ProjectionMode;
use Ultra\Projection\ProjectionPhase;
use Ultra\Projection\ProjectionRuntime;
use Ultra\Projection\Tests\Support\ImmediateModeProjection;
use Ultra\Projection\Tests\Support\ManagedModeProjection;
use Ultra\Projection\Tests\Support\ProjectionModeFixture;
use Ultra\Projection\Tests\Support\ProjectionTestCase;

require_once __DIR__ . '/Support/ProjectionModes.php';

/** @internal */
#[CoversNamespace('Ultra\\Projection')]
#[CoversNamespace('Ultra\\Projection_Postgres')]
final class ManagedActivationTest extends ProjectionTestCase
{
    public static function configurations(): iterable
    {
        foreach (['memory', 'postgres'] as $backend) {
            foreach ([false, true] as $buffered) {
                yield "$backend/buffer=$buffered" => [$backend, $buffered];
            }
        }
    }

    #[DataProvider('configurations')]
    public function test_history_tail_and_live_appends_are_each_applied_once(string $backend, bool $buffered): void
    {
        $f = new ProjectionModeFixture($this->fixture($backend), ProjectionMode::Synchronous, $buffered);
        $f->fixture->store->append([self::courseCreatedEvent('c', 'history')]);
        // Construction must not depend on arrival hints.
        static::assertSame(1, $f->rebuilder->catchUp($f->runtime, batchSize: 1));
        static::assertSame(ProjectionPhase::Building, $f->states->find('mode_projection')->phase);
        $f->store->append([self::courseCreatedEvent('c', 'tail')]);
        static::assertSame(['history'], $f->projection->names());
        static::assertSame(1, $f->rebuilder->activate($f->runtime, 1));
        static::assertSame(ProjectionPhase::Live, $f->states->find('mode_projection')->phase);
        $checkpoint = $f->fixture->segments->listSegments('mode_projection')[0]->checkpoint->serialize();
        $pending = new EventStoreUnitOfWork($f->store);
        $pending->append([self::courseCreatedEvent('c', 'live')]);
        $f->fixture->transactions->transactional($pending->flush(...));
        $pending->clear();
        static::assertSame(['history', 'tail', 'live'], $f->projection->names());
        static::assertSame(
            $checkpoint,
            $f->fixture->segments->listSegments('mode_projection')[0]->checkpoint->serialize(),
        );
        static::assertSame(0, $f->rebuilder->activate($f->runtime));
        static::assertSame(0, $f->rebuilder->catchUp($f->runtime));
        static::assertFalse($f->runner->backfillClaimedOnce(
            $f->runtime,
            'mode_projection',
            0,
            'old-worker',
        )->claimedSegment);
        static::assertSame(['history', 'tail', 'live'], $f->projection->names());
        static::assertSame(1, $f->projection->initialized);
    }

    #[DataProvider('configurations')]
    public function test_async_batches_use_the_same_private_persistence_without_inline_execution(
        string $backend,
        bool $buffered,
    ): void {
        $f = new ProjectionModeFixture($this->fixture($backend), ProjectionMode::Asynchronous, $buffered);
        $f->store->append([self::courseCreatedEvent('c', 'one'), self::courseCreatedEvent('c', 'two')]);
        static::assertSame([], $f->projection->names());
        $f->runner->ensureInitialized($f->runtime);
        $one = $f->runner->runClaimedOnce($f->runtime, 'mode_projection', 0, 'worker', 1);
        static::assertFalse($one->caughtUp);
        static::assertSame(['one'], $f->projection->names());
        $two = $f->runner->runClaimedOnce($f->runtime, 'mode_projection', 0, 'worker', 1);
        static::assertTrue($two->caughtUp);
        static::assertSame(['one', 'two'], $f->projection->names());
        static::assertSame([], $f->states->all());
        static::assertSame([], $f->fixture->activity->affected());
    }

    public function test_failed_activation_rolls_back_the_tail_and_can_resume_without_duplicates(): void
    {
        $f = new ProjectionModeFixture($this->fixture('postgres'), ProjectionMode::Synchronous, true);
        $f->store->append([self::courseCreatedEvent('c', 'history')]);
        $f->rebuilder->catchUp($f->runtime);
        $before = $f->fixture->segments->listSegments('mode_projection')[0]->checkpoint->serialize();
        $f->store->append([self::courseCreatedEvent('c', 'tail')]);
        $f->projection->failAt = 'persistence';
        try {
            $f->rebuilder->activate($f->runtime);
            static::fail('Expected activation failure.');
        } catch (\RuntimeException $e) {
            static::assertSame('Failed persistence.', $e->getMessage());
        }
        static::assertSame(ProjectionPhase::Building, $f->states->find('mode_projection')->phase);
        static::assertSame(['history'], $f->projection->names());
        static::assertSame($before, $f->fixture->segments->listSegments('mode_projection')[0]->checkpoint->serialize());
        static::assertSame([], $f->projection->pending);
        $f->projection->failAt = null;
        static::assertSame(1, $f->rebuilder->activate($f->runtime));
        static::assertSame(['history', 'tail'], $f->projection->names());
    }

    public function test_reset_rebuilds_a_new_generation_and_stale_scheduling_claims_cannot_skip_history(): void
    {
        $f = new ProjectionModeFixture($this->fixture('postgres'), ProjectionMode::Synchronous, true);
        $f->store->append([self::courseCreatedEvent('c', 'one')]);
        $f->rebuilder->catchUp($f->runtime);
        $f->rebuilder->activate($f->runtime);
        $f->store->append([self::courseCreatedEvent('c', 'two')]);
        $f->runner->deleteProjection($f->runtime);
        static::assertSame(2, $f->states->find('mode_projection')->generation);
        static::assertSame(ProjectionPhase::Building, $f->states->find('mode_projection')->phase);
        static::assertSame([], $f->projection->names());
        $f->runner->backfillClaimedOnce($f->runtime, 'mode_projection', 0, 'old-queued-worker');
        $f->rebuilder->activate($f->runtime);
        static::assertSame(['one', 'two'], $f->projection->names());
    }

    public function test_activation_catches_up_all_split_segments_and_revokes_outstanding_claims(): void
    {
        $f = new ProjectionModeFixture($this->fixture('postgres'), ProjectionMode::Synchronous, true);
        $f->runner->ensureInitialized($f->runtime);
        $f->runner->splitSegment('mode_projection', 0);
        $left = self::courseIdInBucketRange(0, 127);
        $right = self::courseIdInBucketRange(128, 255);
        $f->store->append([self::courseCreatedEvent($left, 'left'), self::courseCreatedEvent($right, 'right')]);
        $f->runner->claimSegment('mode_projection', 0, 'queued');
        static::assertSame(2, $f->rebuilder->activate($f->runtime, 1));
        static::assertSame(['left', 'right'], $f->projection->names());
        foreach ($f->fixture->segments->listSegments('mode_projection') as $segment) {
            static::assertNull($segment->workerId);
        }
        static::assertFalse($f->runner->runClaimedOnce($f->runtime, 'mode_projection', 0, 'queued')->claimedSegment);
    }

    public function test_a_writer_missing_a_registered_managed_projection_is_rejected(): void
    {
        $f = new ProjectionModeFixture($this->fixture('postgres'), ProjectionMode::Synchronous);
        $f->rebuilder->activate($f->runtime);
        $f->catalog->add(new ProjectionRuntime(new ImmediateModeProjection(), new UltraNormalizer()));
        try {
            $f->store->append([self::courseCreatedEvent('c', 'bad')]);
            static::fail('Expected incompatible writer rejection.');
        } catch (\LogicException $e) {
            static::assertStringContainsString('incompatible managed projection', $e->getMessage());
        }
        static::assertSame([], $f->fixture->store->load(StreamQuery::all())->events);
    }

    public function test_one_append_routes_mixed_modes_and_the_coordinator_discards_obsolete_live_activity(): void
    {
        $f = new ProjectionModeFixture($this->fixture('memory'), ProjectionMode::Synchronous, true);
        $immediate = new \Ultra\Projection\Tests\Support\SecondImmediateModeProjection();
        $async = new \Ultra\Projection\Tests\Support\AdditionalAsyncModeProjection();
        $f->catalog->add(new ProjectionRuntime($immediate, new UltraNormalizer()));
        $f->catalog->add(new ProjectionRuntime($async, new UltraNormalizer()));
        $f->store->append([self::courseCreatedEvent('c', 'building')]);
        static::assertSame(['building'], $immediate->names());
        static::assertSame([], $async->names());
        static::assertSame([], $f->projection->names());
        $f->rebuilder->activate($f->runtime);
        $f->store->append([self::courseCreatedEvent('c', 'live')]);
        static::assertSame(['building', 'live'], $immediate->names());
        static::assertSame(['building', 'live'], $f->projection->names());
        static::assertSame([], $async->names());
        $coordinator = new \Ultra\Projection\Coordination\ProjectionCoordinator(
            $f->catalog,
            $f->runner,
            $f->fixture->activity,
            $f->fixture->leases,
            $f->fixture->activator,
        );
        $coordinator->run('coordinator');
        static::assertSame(['building', 'live'], $async->names());
        static::assertSame([], $f->fixture->activity->affected());
        static::assertSame(['building', 'live'], $f->projection->names());
    }

    public function test_buffered_reset_and_initialization_are_flushed_once_each(): void
    {
        $f = new ProjectionModeFixture($this->fixture('postgres'), ProjectionMode::Synchronous, true);
        $projection = new \Ultra\Projection\Tests\Support\BufferedLifecycleProjection(
            $f->fixture->connection,
            $f->projection->table,
        );
        $runtime = new ProjectionRuntime($projection, new UltraNormalizer());
        $f->runner->ensureInitialized($runtime);
        static::assertSame(['initialize'], $projection->names());
        $f->runner->deleteProjection($runtime);
        static::assertSame(['initialize', 'reset', 'initialize'], $projection->names());
        static::assertSame([], $projection->pending);
    }

    public function test_buffered_initialization_failure_rolls_back_state_and_releases_its_buffer(): void
    {
        $f = new ProjectionModeFixture($this->fixture('postgres'), ProjectionMode::Synchronous, true);
        $projection = new \Ultra\Projection\Tests\Support\BufferedLifecycleProjection(
            $f->fixture->connection,
            $f->projection->table,
        );
        $runtime = new ProjectionRuntime($projection, new UltraNormalizer());
        $projection->failAt = 'initialize';
        try {
            $f->runner->ensureInitialized($runtime);
            static::fail('Expected initializer failure.');
        } catch (\RuntimeException $e) {
            static::assertSame('Failed initialize.', $e->getMessage());
        }
        static::assertNull($f->states->find('mode_projection'));
        static::assertSame([], $projection->names());
        static::assertSame([], $projection->pending);
        $projection->failAt = null;
        $f->runner->ensureInitialized($runtime);
        static::assertSame(['initialize'], $projection->names());
    }

    public function test_an_async_worker_rechecks_its_definition_if_activation_happens_during_loading(): void
    {
        $f = new ProjectionModeFixture($this->fixture('postgres'), ProjectionMode::Synchronous, true);
        $f->store->append([self::courseCreatedEvent('c', 'history')]);
        $obsolete = new ProjectionRuntime(
            new \Ultra\Projection\Tests\Support\AsyncBufferedModeProjection(
                $f->fixture->connection,
                $f->projection->table,
            ),
            new UltraNormalizer(),
        );
        $store = new \Ultra\Projection\Tests\Support\CallbackEventStore($f->fixture->store, function () use ($f): void {
            $f->rebuilder->activate($f->runtime);
        });
        $runner = new \Ultra\Projection\ProjectionRunner(
            $store,
            $f->fixture->segments,
            $f->fixture->transactions,
            $f->fixture->activity,
            $f->states,
        );
        try {
            $runner->backfillClaimedOnce($obsolete, 'mode_projection', 0, 'old-worker');
            static::fail('The loaded batch must not be applied after activation.');
        } catch (\LogicException $e) {
            static::assertStringContainsString('Projection definition changed', $e->getMessage());
        }
        static::assertSame(['history'], $f->projection->names());
        static::assertSame(ProjectionPhase::Live, $f->states->find('mode_projection')->phase);
    }

    public function test_an_obsolete_async_worker_cannot_replay_or_reset_a_managed_projection(): void
    {
        $f = new ProjectionModeFixture($this->fixture('postgres'), ProjectionMode::Synchronous, true);
        $f->rebuilder->activate($f->runtime);
        $f->store->append([self::courseCreatedEvent('c', 'live')]);
        $obsolete = new ProjectionRuntime(
            new \Ultra\Projection\Tests\Support\AsyncBufferedModeProjection(
                $f->fixture->connection,
                $f->projection->table,
            ),
            new UltraNormalizer(),
        );
        foreach (['backfill', 'reset'] as $operation) {
            try {
                if ('backfill' === $operation) {
                    $f->runner->backfillClaimedOnce($obsolete, 'mode_projection', 0, 'old-worker');
                } else {
                    $f->runner->deleteProjection($obsolete);
                }
                static::fail('An obsolete worker must be fenced.');
            } catch (\LogicException $e) {
                static::assertStringContainsString('Projection definition changed', $e->getMessage());
            }
        }
        static::assertSame(['live'], $f->projection->names());
        static::assertSame(ProjectionPhase::Live, $f->states->find('mode_projection')->phase);
    }

    public function test_immediate_mode_has_no_automatic_replay_lifecycle(): void
    {
        $f = new ProjectionModeFixture($this->fixture('memory'), ProjectionMode::SynchronousFromNow);
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('application-managed initialization');
        $f->runner->ensureInitialized($f->runtime);
    }
}
