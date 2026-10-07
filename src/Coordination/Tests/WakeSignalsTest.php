<?php

declare(strict_types=1);

namespace Ultra\Coordination\Tests;

use PHPUnit\Framework\Attributes\CoversNamespace;
use PHPUnit\Framework\Attributes\DataProvider;
use Ultra\Coordination\InMemory\InMemoryActivator;
use Ultra\Coordination\Tests\Support\CoordinationTestCase;
use Ultra\Time\Duration;
use Ultra\Time\StatickClock;

/** @internal */
#[CoversNamespace('Ultra\Coordination')]
#[CoversNamespace('Ultra\Coordination_Postgres')]
final class WakeSignalsTest extends CoordinationTestCase
{
    #[DataProvider('backends')]
    public function test_wakes_are_debounced_per_key_until_ttl_and_suppressed_until_the_holder_expires(string $backend): void
    {
        $clock = new StatickClock(new \DateTimeImmutable('2026-01-01T00:00:00Z'));
        $lease = $this->lease($backend, $clock);
        static::assertTrue($lease->markWakeEnqueued('a'));
        static::assertTrue($lease->markWakeEnqueued('b'));
        static::assertFalse($lease->markWakeEnqueued('a'));
        $clock->sleep(new Duration(seconds: 30));
        static::assertFalse($lease->markWakeEnqueued('a'));
        $clock->sleep(new Duration(seconds: 1));
        static::assertTrue($lease->markWakeEnqueued('a', new Duration(seconds: 10)));
        $clock->sleep(new Duration(seconds: 10));
        static::assertFalse($lease->markWakeEnqueued('a'));
        $clock->sleep(new Duration(seconds: 1));
        static::assertTrue($lease->markWakeEnqueued('a'));
        static::assertTrue($lease->acquire('a', 'w1', new Duration(seconds: 5)));
        static::assertFalse($lease->markWakeEnqueued('a'));
        $lease->release('a', 'w1');
        static::assertTrue($lease->markWakeEnqueued('a'));
        static::assertTrue($lease->acquire('a', 'w2', new Duration(seconds: 5)));
        $clock->sleep(new Duration(seconds: 5));
        static::assertFalse($lease->markWakeEnqueued('a'));
        $clock->sleep(new Duration(seconds: 1));
        static::assertTrue($lease->markWakeEnqueued('a'));
    }

    public function test_the_in_memory_activator_records_each_work_signal(): void
    {
        $activator = new InMemoryActivator();
        static::assertSame(0, $activator->workSignals());
        $activator->signalWorkAvailable();
        $activator->signalWorkAvailable();
        static::assertSame(2, $activator->workSignals());
    }
}
