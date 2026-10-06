<?php

declare(strict_types=1);

namespace Ultra\Testing\Tests\Concurrent;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use Ultra\Testing\Concurrent\Cluster;
use Ultra\Testing\Concurrent\Worker;
use Ultra\Testing\Tests\Support\ConcurrentTestCase;

/** @internal */
#[CoversClass(Cluster::class)]
#[CoversClass(Worker::class)]
#[Group('slow')]
final class CheckpointCoordinationTest extends ConcurrentTestCase
{
    public function test_a_worker_delivers_payload_and_waits_for_each_named_release(): void
    {
        $worker = $this->cluster->spawn(static function (): array {
            $first = checkpoint('first', ['version' => 7]);
            $second = checkpoint('second');
            return [$first, $second];
        });

        $first = $worker->awaitCheckpoint('first');
        self::assertSame('first', $first->name);
        self::assertSame(['version' => 7], $first->payload);
        self::assertNull($worker->outcome());
        $worker->release('first', ['injected' => 'yes']);
        $second = $worker->awaitCheckpoint('second');
        self::assertSame('second', $second->name);
        self::assertNull($second->payload);
        self::assertNull($worker->outcome());
        $worker->release('second');
        self::assertSame([['injected' => 'yes'], null], $worker->join()->value());
    }

    public function test_uninteresting_checkpoints_can_be_released_while_waiting_for_the_target(): void
    {
        $worker = $this->cluster->spawn(static function (): string {
            checkpoint('setup');
            checkpoint('init');
            checkpoint('ready');
            return 'reached';
        });
        self::assertSame('ready', $worker->awaitCheckpoint('ready', releaseOthers: true)->name);
        $worker->release('ready');
        self::assertSame('reached', $worker->join()->value());
    }

    public function test_broadcast_release_delivers_the_same_injection_to_independent_workers(): void
    {
        $workers = [];
        foreach (['first', 'second'] as $identity) {
            $workers[] = $this->cluster->spawn(static function () use ($identity): array {
                return [$identity, checkpoint('gate')];
            });
        }
        foreach ($workers as $worker) {
            $worker->awaitCheckpoint('gate');
            self::assertNull($worker->outcome());
        }
        $this->cluster->releaseAll('gate', 'shared-value');
        $outcomes = $this->cluster->joinAll();
        self::assertCount(2, $outcomes);
        foreach ($outcomes as $outcome) {
            self::assertTrue($outcome->isSuccess());
        }
        self::assertSame([['first', 'shared-value'], ['second', 'shared-value']], array_map(static fn ($outcome) => $outcome->value(), $outcomes));
    }

    public function test_join_all_preserves_spawn_order_despite_reverse_completion_and_failure(): void
    {
        $first = $this->cluster->spawn(static function (): string {
            checkpoint('gate');
            return 'first';
        });
        $second = $this->cluster->spawn(static function (): string {
            checkpoint('gate');
            return 'second';
        });
        $third = $this->cluster->spawn(static function (): never {
            checkpoint('gate');
            throw new \RuntimeException('worker failed');
        });
        foreach ([$first, $second, $third] as $worker) {
            $worker->awaitCheckpoint('gate');
        }
        $third->release('gate');
        self::assertTrue($third->join()->isFailure());
        $second->release('gate');
        self::assertSame('second', $second->join()->value());
        self::assertNull($first->outcome());
        $first->release('gate');
        $outcomes = $this->cluster->joinAll();
        self::assertCount(3, $outcomes);
        self::assertSame('first', $outcomes[0]->value());
        self::assertSame('second', $outcomes[1]->value());
        self::assertTrue($outcomes[0]->isSuccess());
        self::assertTrue($outcomes[2]->isFailure());
        self::assertSame('worker failed', $outcomes[2]->exceptionMessage());
    }
}
