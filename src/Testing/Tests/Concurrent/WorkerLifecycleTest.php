<?php

declare(strict_types=1);

namespace Ultra\Testing\Tests\Concurrent;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RequiresPhpExtension;
use Ultra\Testing\Concurrent\Cluster;
use Ultra\Testing\Concurrent\Exception\ConcurrentTestTimeoutException;
use Ultra\Testing\Concurrent\Exception\WorkerDiedException;
use Ultra\Testing\Concurrent\Internal\WorkerRuntime;
use Ultra\Testing\Concurrent\Outcome;
use Ultra\Testing\Concurrent\Worker;
use Ultra\Testing\Tests\Support\ConcurrentTestCase;
use Ultra\Testing\Tests\Support\UnserializableFailure;

/** @internal */
#[CoversClass(Cluster::class)]
#[CoversClass(Worker::class)]
#[CoversClass(Outcome::class)]
#[Group('slow')]
final class WorkerLifecycleTest extends ConcurrentTestCase
{
    /** @return iterable<string, array{mixed}> */
    public static function results(): iterable
    {
        yield 'integer' => [42];
        yield 'null' => [null];
        yield 'structured result' => [['ok' => false, 'text' => 'héllo 🎉']];
    }

    #[DataProvider('results')]
    public function test_success_returns_the_value_without_exception_metadata(mixed $value): void
    {
        $worker = $this->cluster->spawn(static fn (): mixed => $value);
        $outcome = $worker->join();
        self::assertTrue($outcome->isSuccess());
        self::assertFalse($outcome->isFailure());
        self::assertSame($value, $outcome->value());
        self::assertNull($outcome->exception());
        self::assertSame('', $outcome->exceptionClass());
        self::assertSame('', $outcome->exceptionMessage());
        self::assertSame($outcome, $worker->outcome());
        self::assertSame($outcome, $worker->join());
    }

    public function test_failure_preserves_the_exception_and_collects_logs_and_stderr(): void
    {
        $worker = $this->cluster->spawn(static function (): never {
            WorkerRuntime::log('hello from worker', 'info');
            fwrite(STDERR, "boom from child\n");
            throw new \OverflowException('overflow!', 17);
        });
        $outcome = $worker->join();
        self::assertTrue($outcome->isFailure());
        self::assertFalse($outcome->isSuccess());
        self::assertNull($outcome->value());
        self::assertSame(\OverflowException::class, $outcome->exceptionClass());
        self::assertSame('overflow!', $outcome->exceptionMessage());
        self::assertInstanceOf(\OverflowException::class, $outcome->exception());
        self::assertSame(17, $outcome->exception()->getCode());
        self::assertSame([['level' => 'info', 'message' => 'hello from worker']], $worker->logs());
        self::assertStringContainsString('boom from child', $worker->stderr());
    }

    public function test_unserializable_failure_keeps_its_class_and_message(): void
    {
        $worker = $this->cluster->spawn(static function (): never {
            throw new UnserializableFailure('description survives');
        });
        $outcome = $worker->join();
        self::assertTrue($outcome->isFailure());
        self::assertSame(UnserializableFailure::class, $outcome->exceptionClass());
        self::assertSame('description survives', $outcome->exceptionMessage());
        self::assertInstanceOf(\RuntimeException::class, $outcome->exception());
        self::assertSame('['.UnserializableFailure::class.'] description survives', $outcome->exception()->getMessage());
    }

    /** @return iterable<string, array{string}> */
    public static function waitOperations(): iterable
    {
        yield 'checkpoint' => ['checkpoint'];
        yield 'join' => ['join'];
    }

    #[DataProvider('waitOperations')]
    public function test_unreported_process_exit_surfaces_exit_code_and_stderr(string $operation): void
    {
        $worker = $this->cluster->spawn(static function (): never {
            fwrite(STDERR, "fatal noise\n");
            exit(77);
        });
        try {
            'checkpoint' === $operation ? $worker->awaitCheckpoint('never') : $worker->join();
            self::fail('Expected WorkerDiedException');
        } catch (WorkerDiedException $e) {
            self::assertStringContainsString('Worker died (exit code: 77)', $e->getMessage());
            self::assertStringContainsString('fatal noise', $e->getMessage());
            self::assertStringContainsString('checkpoint' === $operation ? 'checkpoint "never"' : 'without emitting a result message', $e->getMessage());
        }
    }

    /** @return iterable<string, array{string, ?float}> */
    public static function deadlines(): iterable
    {
        foreach (['checkpoint', 'join'] as $operation) {
            yield $operation.' default deadline' => [$operation, null];
            yield $operation.' overridden deadline' => [$operation, 0.1];
        }
    }

    #[DataProvider('deadlines')]
    public function test_waiting_on_a_ready_but_blocked_worker_respects_the_deadline(string $operation, ?float $timeout): void
    {
        $cluster = new Cluster(timeout: 0.5);
        $worker = $cluster->spawn(static function (): void { checkpoint('hold'); });
        $worker->awaitCheckpoint('hold', 3.0);
        $started = microtime(true);
        try {
            'checkpoint' === $operation ? $worker->awaitCheckpoint('never', $timeout) : $worker->join($timeout);
            self::fail('Expected ConcurrentTestTimeoutException');
        } catch (ConcurrentTestTimeoutException $e) {
            self::assertStringContainsString(sprintf('Timed out after %.1fs', $timeout ?? 0.5), $e->getMessage());
            self::assertStringContainsString('Stderr: (empty)', $e->getMessage());
            self::assertGreaterThanOrEqual(($timeout ?? 0.5) * 0.8, microtime(true) - $started);
            self::assertLessThan(2.0, microtime(true) - $started);
        } finally {
            $worker->stop();
        }
    }

    /** @return iterable<string, array{string}> */
    public static function terminationActions(): iterable
    {
        yield 'abort' => ['abort'];
        yield 'explicit stop' => ['stop'];
        yield 'cluster destruction' => ['destroy'];
    }

    #[RequiresPhpExtension('posix')]
    #[DataProvider('terminationActions')]
    public function test_termination_removes_the_real_process_even_while_a_worker_reference_remains(string $action): void
    {
        $cluster = new Cluster();
        $worker = $cluster->spawn(static function (): void { checkpoint('waiting', getmypid()); });
        $pid = $worker->awaitCheckpoint('waiting')->payload;
        self::assertIsInt($pid);
        self::assertTrue(posix_kill($pid, 0), 'The child must exist before termination.');
        try {
            $started = microtime(true);
            match ($action) {
                'abort' => $worker->abort(),
                'stop' => $worker->stop(),
                'destroy' => $cluster = null,
            };
            self::assertFalse(posix_kill($pid, 0), 'Termination must remove the child process.');
            self::assertLessThan(2.0, microtime(true) - $started);
            $worker->stop(); // Repeated cleanup is safe.
        } finally {
            if (posix_kill($pid, 0)) {
                posix_kill($pid, SIGKILL);
            }
            $worker->stop();
        }
    }
}
