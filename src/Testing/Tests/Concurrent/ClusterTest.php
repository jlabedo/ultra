<?php

declare(strict_types=1);

namespace Ultra\Testing\Tests\Concurrent;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Ultra\Testing\Concurrent\Cluster;
use Ultra\Testing\Concurrent\Outcome;

/**
 * Multi-worker coordination tests.
 *
 * @internal
 */
#[CoversClass(Cluster::class)]
#[Group('slow')]
final class ClusterTest extends TestCase
{
    public function test_two_workers_reach_same_checkpoint_and_are_released_together(): void
    {
        $cluster = new Cluster(timeout: 5.0);

        $w1 = $cluster->spawn(static function (): string {
            checkpoint('loaded');

            return 'w1';
        });

        $w2 = $cluster->spawn(static function (): string {
            checkpoint('loaded');

            return 'w2';
        });

        $w1->awaitCheckpoint('loaded');
        $w2->awaitCheckpoint('loaded');

        $cluster->releaseAll('loaded');

        $outcomes = $cluster->joinAll();

        self::assertCount(2, $outcomes);
        self::assertTrue($outcomes[0]->isSuccess());
        self::assertTrue($outcomes[1]->isSuccess());
        self::assertSame('w1', $outcomes[0]->value());
        self::assertSame('w2', $outcomes[1]->value());
    }

    public function test_release_all_provides_inject_to_all_workers(): void
    {
        $cluster = new Cluster(timeout: 5.0);

        $w1 = $cluster->spawn(static function (): mixed {
            return checkpoint('gate');
        });

        $w2 = $cluster->spawn(static function (): mixed {
            return checkpoint('gate');
        });

        $w1->awaitCheckpoint('gate');
        $w2->awaitCheckpoint('gate');

        $cluster->releaseAll('gate', 'shared-value');

        $outcomes = $cluster->joinAll();

        self::assertSame('shared-value', $outcomes[0]->value());
        self::assertSame('shared-value', $outcomes[1]->value());
    }

    public function test_join_all_returns_outcomes_in_spawn_order(): void
    {
        $cluster = new Cluster(timeout: 5.0);

        $w1 = $cluster->spawn(static fn (): int => 1);
        $w2 = $cluster->spawn(static fn (): int => 2);
        $w3 = $cluster->spawn(static fn (): int => 3);

        $outcomes = $cluster->joinAll();

        self::assertSame(1, $outcomes[0]->value());
        self::assertSame(2, $outcomes[1]->value());
        self::assertSame(3, $outcomes[2]->value());
    }

    public function test_mixed_success_and_failure_outcomes(): void
    {
        $cluster = new Cluster(timeout: 5.0);

        $w1 = $cluster->spawn(static fn (): string => 'ok');

        $w2 = $cluster->spawn(static function (): never {
            throw new \RuntimeException('worker failed');
        });

        $outcomes = $cluster->joinAll();

        self::assertTrue($outcomes[0]->isSuccess());
        self::assertTrue($outcomes[1]->isFailure());
        self::assertSame('worker failed', $outcomes[1]->exceptionMessage());
    }

    public function test_cluster_destroys_workers_without_hanging(): void
    {
        // Workers stuck at a checkpoint — cluster __destruct must kill them cleanly.
        $cluster = new Cluster(timeout: 5.0);

        $w = $cluster->spawn(static function (): void {
            checkpoint('never_released');
            sleep(30);
        });

        $w->awaitCheckpoint('never_released');

        // Destroying the cluster must not block.
        unset($cluster);

        self::assertTrue(true);
    }

    public function test_spawn_returns_independent_workers(): void
    {
        $cluster = new Cluster(timeout: 5.0);

        $w1 = $cluster->spawn(static function (): string {
            checkpoint('sync');

            return 'first';
        });

        $w2 = $cluster->spawn(static function (): string {
            checkpoint('sync');

            return 'second';
        });

        // Both must reach the checkpoint independently before we release.
        $w1->awaitCheckpoint('sync');
        $w2->awaitCheckpoint('sync');

        // Neither should have finished yet.
        self::assertNull($w1->outcome());
        self::assertNull($w2->outcome());

        $w1->release('sync');
        $w2->release('sync');

        $outcomes = $cluster->joinAll();
        self::assertSame('first', $outcomes[0]->value());
        self::assertSame('second', $outcomes[1]->value());
    }

    public function test_one_success_one_concurrency_type_failure(): void
    {
        // Simulates the archetypal optimistic-concurrency race:
        // both workers "read the same version", one write succeeds, one detects conflict.
        $cluster = new Cluster(timeout: 5.0);

        $shared = ['appended' => false];

        $w1 = $cluster->spawn(static function (): string {
            checkpoint('read');

            // w1 wins the race — simulate a "success" write
            return 'appended';
        });

        $w2 = $cluster->spawn(static function (): string {
            checkpoint('read');

            // w2 loses — simulate a "conflict" exception path
            throw new \RuntimeException('ConcurrencyException: version mismatch');
        });

        $w1->awaitCheckpoint('read');
        $w2->awaitCheckpoint('read');

        $cluster->releaseAll('read');

        $outcomes = $cluster->joinAll();

        $successes = array_filter($outcomes, static fn (Outcome $o): bool => $o->isSuccess());
        $failures = array_filter($outcomes, static fn (Outcome $o): bool => $o->isFailure());

        self::assertCount(1, $successes);
        self::assertCount(1, $failures);
    }
}
