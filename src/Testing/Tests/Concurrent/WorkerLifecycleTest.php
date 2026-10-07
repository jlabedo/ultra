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
        $worker = $this->cluster->spawn(static fn(): mixed => $value);
        $outcome = $worker->join();
        static::assertTrue($outcome->isSuccess());
        static::assertFalse($outcome->isFailure());
        static::assertSame($value, $outcome->value());
        static::assertNull($outcome->exception());
        static::assertSame('', $outcome->exceptionClass());
        static::assertSame('', $outcome->exceptionMessage());
        static::assertSame($outcome, $worker->outcome());
        static::assertSame($outcome, $worker->join());
    }

    public function test_failure_preserves_the_exception_and_collects_logs_and_stderr(): void
    {
        $worker = $this->cluster->spawn(static function (): never {
            WorkerRuntime::log('hello from worker', 'info');
            fwrite(STDERR, "boom from child\n");
            throw new \OverflowException('overflow!', 17);
        });
        $outcome = $worker->join();
        static::assertTrue($outcome->isFailure());
        static::assertFalse($outcome->isSuccess());
        static::assertNull($outcome->value());
        static::assertSame(\OverflowException::class, $outcome->exceptionClass());
        static::assertSame('overflow!', $outcome->exceptionMessage());
        static::assertInstanceOf(\OverflowException::class, $outcome->exception());
        static::assertSame(17, $outcome->exception()->getCode());
        static::assertSame([['level' => 'info', 'message' => 'hello from worker']], $worker->logs());
        static::assertStringContainsString('boom from child', $worker->stderr());
    }

    public function test_unserializable_failure_keeps_its_class_and_message(): void
    {
        $worker = $this->cluster->spawn(static function (): never {
            throw new UnserializableFailure('description survives');
        });
        $outcome = $worker->join();
        static::assertTrue($outcome->isFailure());
        static::assertSame(UnserializableFailure::class, $outcome->exceptionClass());
        static::assertSame('description survives', $outcome->exceptionMessage());
        static::assertInstanceOf(\RuntimeException::class, $outcome->exception());
        static::assertSame(
            '[' . UnserializableFailure::class . '] description survives',
            $outcome->exception()->getMessage(),
        );
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
            static::fail('Expected WorkerDiedException');
        } catch (WorkerDiedException $e) {
            static::assertStringContainsString('Worker died (exit code: 77)', $e->getMessage());
            static::assertStringContainsString('fatal noise', $e->getMessage());
            static::assertStringContainsString(
                'checkpoint' === $operation ? 'checkpoint "never"' : 'without emitting a result message',
                $e->getMessage(),
            );
        }
    }

    /** @return iterable<string, array{string, ?float}> */
    public static function deadlines(): iterable
    {
        foreach (['checkpoint', 'join'] as $operation) {
            yield $operation . ' default deadline' => [$operation, null];
            yield $operation . ' overridden deadline' => [$operation, 0.1];
        }
    }

    #[DataProvider('deadlines')]
    public function test_waiting_on_a_ready_but_blocked_worker_respects_the_deadline(
        string $operation,
        ?float $timeout,
    ): void {
        $cluster = new Cluster(timeout: 0.5);
        $worker = $cluster->spawn(static function (): void {
            checkpoint('hold');
        });
        $worker->awaitCheckpoint('hold', 3.0);
        $started = microtime(true);
        try {
            'checkpoint' === $operation ? $worker->awaitCheckpoint('never', $timeout) : $worker->join($timeout);
            static::fail('Expected ConcurrentTestTimeoutException');
        } catch (ConcurrentTestTimeoutException $e) {
            static::assertStringContainsString(sprintf('Timed out after %.1fs', $timeout ?? 0.5), $e->getMessage());
            static::assertStringContainsString('Stderr: (empty)', $e->getMessage());
            static::assertGreaterThanOrEqual(($timeout ?? 0.5) * 0.8, microtime(true) - $started);
            static::assertLessThan(2.0, microtime(true) - $started);
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
        $worker = $cluster->spawn(static function (): void {
            checkpoint('waiting', getmypid());
        });
        $pid = $worker->awaitCheckpoint('waiting')->payload;
        static::assertIsInt($pid);
        static::assertTrue(posix_kill($pid, 0), 'The child must exist before termination.');
        try {
            $started = microtime(true);
            match ($action) {
                'abort' => $worker->abort(),
                'stop' => $worker->stop(),
                'destroy' => $cluster = null,
            };
            static::assertFalse(posix_kill($pid, 0), 'Termination must remove the child process.');
            static::assertLessThan(2.0, microtime(true) - $started);
            $worker->stop(); // Repeated cleanup is safe.
        } finally {
            if (posix_kill($pid, 0)) {
                posix_kill($pid, SIGKILL);
            }
            $worker->stop();
        }
    }
}
