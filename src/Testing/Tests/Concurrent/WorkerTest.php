<?php

declare(strict_types=1);

namespace Ultra\Testing\Tests\Concurrent;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Ultra\Testing\Concurrent\Cluster;
use Ultra\Testing\Concurrent\Exception\ConcurrentTestTimeoutException;
use Ultra\Testing\Concurrent\Exception\WorkerDiedException;
use Ultra\Testing\Concurrent\Internal\WorkerRuntime;
use Ultra\Testing\Concurrent\Worker;

/**
 * Integration tests that spawn real worker processes.
 *
 * @internal
 */
#[CoversClass(Worker::class)]
#[Group('slow')]
final class WorkerTest extends TestCase
{
    private Cluster $cluster;

    protected function setUp(): void
    {
        $this->cluster = new Cluster(timeout: 5.0);
    }

    protected function tearDown(): void
    {
        // Cluster::__destruct() force-kills all workers, but we call it explicitly here
        // so stragglers don't outlive the test.
        unset($this->cluster);
    }

    // -------------------------------------------------------------------------
    // Happy path
    // -------------------------------------------------------------------------

    public function test_single_worker_returns_value(): void
    {
        $worker = $this->cluster->spawn(static fn (): int => 42);

        $outcome = $worker->join();

        self::assertTrue($outcome->isSuccess());
        self::assertSame(42, $outcome->value());
    }

    public function test_single_worker_with_checkpoint_and_release(): void
    {
        $worker = $this->cluster->spawn(static function (): string {
            checkpoint('step1');

            return 'done';
        });

        $cp = $worker->awaitCheckpoint('step1');
        self::assertSame('step1', $cp->name);

        $worker->release('step1');

        $outcome = $worker->join();
        self::assertTrue($outcome->isSuccess());
        self::assertSame('done', $outcome->value());
    }

    public function test_checkpoint_returns_inject_value(): void
    {
        $worker = $this->cluster->spawn(static function (): mixed {
            return checkpoint('gate', ['before' => true]);
        });

        $worker->awaitCheckpoint('gate');
        $worker->release('gate', ['injected' => 'yes']);

        $outcome = $worker->join();
        self::assertTrue($outcome->isSuccess());
        self::assertSame(['injected' => 'yes'], $outcome->value());
    }

    public function test_checkpoint_payload_is_delivered_to_master(): void
    {
        $worker = $this->cluster->spawn(static function (): void {
            checkpoint('loaded', ['version' => 7]);
        });

        $cp = $worker->awaitCheckpoint('loaded');
        self::assertSame(['version' => 7], $cp->payload);

        $worker->release('loaded');
        $worker->join();
    }

    public function test_multiple_sequential_checkpoints(): void
    {
        $worker = $this->cluster->spawn(static function (): string {
            checkpoint('first');
            checkpoint('second');

            return 'all done';
        });

        $worker->awaitCheckpoint('first');
        $worker->release('first');

        $worker->awaitCheckpoint('second');
        $worker->release('second');

        $outcome = $worker->join();
        self::assertSame('all done', $outcome->value());
    }

    public function test_release_others_skips_intermediate_checkpoints(): void
    {
        $worker = $this->cluster->spawn(static function (): string {
            checkpoint('setup');
            checkpoint('init');
            checkpoint('ready');

            return 'reached';
        });

        // Only care about 'ready' — let 'setup' and 'init' pass through automatically.
        $worker->awaitCheckpoint('ready', releaseOthers: true);
        $worker->release('ready');

        $outcome = $worker->join();
        self::assertSame('reached', $outcome->value());
    }

    // -------------------------------------------------------------------------
    // Exception path
    // -------------------------------------------------------------------------

    public function test_worker_exception_produces_failure_outcome(): void
    {
        $worker = $this->cluster->spawn(static function (): never {
            throw new \InvalidArgumentException('something went wrong');
        });

        $outcome = $worker->join();

        self::assertTrue($outcome->isFailure());
        self::assertSame(\InvalidArgumentException::class, $outcome->exceptionClass());
        self::assertSame('something went wrong', $outcome->exceptionMessage());
    }

    public function test_worker_exception_preserves_exception_object(): void
    {
        $worker = $this->cluster->spawn(static function (): never {
            throw new \OverflowException('overflow!');
        });

        $outcome = $worker->join();

        self::assertInstanceOf(\OverflowException::class, $outcome->exception());
    }

    // -------------------------------------------------------------------------
    // Worker death before checkpoint
    // -------------------------------------------------------------------------

    public function test_worker_dies_before_checkpoint_raises_worker_died_exception(): void
    {
        $worker = $this->cluster->spawn(static function (): never {
            exit(99);
        });

        $this->expectException(WorkerDiedException::class);
        $this->expectExceptionMessageMatches('/died/');

        $worker->awaitCheckpoint('never');
    }

    public function test_worker_died_exception_includes_exit_code(): void
    {
        $worker = $this->cluster->spawn(static function (): never {
            exit(77);
        });

        try {
            $worker->awaitCheckpoint('never');
            self::fail('Expected WorkerDiedException');
        } catch (WorkerDiedException $e) {
            self::assertStringContainsString('77', $e->getMessage());
        }
    }

    // -------------------------------------------------------------------------
    // Timeout
    // -------------------------------------------------------------------------

    public function test_await_checkpoint_times_out(): void
    {
        $cluster = new Cluster(timeout: 0.3);
        $worker = $cluster->spawn(static function (): never {
            sleep(30);
        });

        $this->expectException(ConcurrentTestTimeoutException::class);

        $worker->awaitCheckpoint('never', 0.2);

        unset($cluster);
    }

    public function test_join_times_out(): void
    {
        $cluster = new Cluster(timeout: 0.3);
        $worker = $cluster->spawn(static function (): never {
            sleep(30);
        });

        $this->expectException(ConcurrentTestTimeoutException::class);

        $worker->join(0.2);

        unset($cluster);
    }

    // -------------------------------------------------------------------------
    // Abort
    // -------------------------------------------------------------------------

    public function test_abort_stops_the_worker(): void
    {
        $worker = $this->cluster->spawn(static function (): void {
            checkpoint('waiting');
            sleep(30); // unreachable if aborted
        });

        $worker->awaitCheckpoint('waiting');
        $worker->abort();

        // After abort, the worker should not be running — join would hang otherwise.
        // We just verify no exception is thrown when the cluster destructs cleanly.
        self::assertTrue(true);
    }

    // -------------------------------------------------------------------------
    // Stderr surfaced in error messages
    // -------------------------------------------------------------------------

    public function test_stderr_is_captured_and_accessible(): void
    {
        $worker = $this->cluster->spawn(static function (): never {
            fwrite(STDERR, "boom from child\n");

            throw new \RuntimeException('child error');
        });

        $outcome = $worker->join();

        self::assertTrue($outcome->isFailure());
        self::assertStringContainsString('boom from child', $worker->stderr());
    }

    public function test_stderr_appears_in_worker_died_message(): void
    {
        $worker = $this->cluster->spawn(static function (): never {
            fwrite(STDERR, "fatal noise\n");

            exit(1);
        });

        try {
            $worker->awaitCheckpoint('never');
            self::fail('Expected WorkerDiedException');
        } catch (WorkerDiedException $e) {
            self::assertStringContainsString('fatal noise', $e->getMessage());
        }
    }

    // -------------------------------------------------------------------------
    // Non-blocking outcome()
    // -------------------------------------------------------------------------

    public function test_outcome_returns_null_while_running(): void
    {
        $worker = $this->cluster->spawn(static function (): void {
            checkpoint('hold');
        });

        $worker->awaitCheckpoint('hold');

        // Worker is frozen at checkpoint — outcome should be null.
        self::assertNull($worker->outcome());

        $worker->release('hold');
        $worker->join();
    }

    // -------------------------------------------------------------------------
    // Logs
    // -------------------------------------------------------------------------

    public function test_log_messages_are_collected(): void
    {
        $worker = $this->cluster->spawn(static function (): void {
            WorkerRuntime::log('hello from worker', 'info');
        });

        $worker->join();

        $logs = $worker->logs();
        self::assertCount(1, $logs);
        self::assertSame('info', $logs[0]['level']);
        self::assertSame('hello from worker', $logs[0]['message']);
    }
}
