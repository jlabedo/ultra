<?php

declare(strict_types=1);

namespace Ultra\Testing\Concurrent;

use Symfony\Component\Process\InputStream;
use Symfony\Component\Process\Process;
use Ultra\Testing\Concurrent\Exception\ConcurrentTestTimeoutException;
use Ultra\Testing\Concurrent\Exception\ProtocolException;
use Ultra\Testing\Concurrent\Exception\WorkerDiedException;
use Ultra\Testing\Concurrent\Protocol\AbortMessage;
use Ultra\Testing\Concurrent\Protocol\CheckpointMessage;
use Ultra\Testing\Concurrent\Protocol\Codec;
use Ultra\Testing\Concurrent\Protocol\ExceptionMessage;
use Ultra\Testing\Concurrent\Protocol\InitMessage;
use Ultra\Testing\Concurrent\Protocol\LogMessage;
use Ultra\Testing\Concurrent\Protocol\Message;
use Ultra\Testing\Concurrent\Protocol\ReadyMessage;
use Ultra\Testing\Concurrent\Protocol\ResultMessage;

final class Worker
{
    private readonly Process $process;
    private readonly InputStream $input;

    /** Unprocessed bytes received from the child's stdout. */
    private string $stdoutRemainder = '';

    /** Full accumulated stderr from the child. */
    private string $stderrBuffer = '';

    /** @var array<string, list<Checkpoint>> Queued checkpoints keyed by name. */
    private array $checkpointQueues = [];

    private ?Outcome $outcome = null;

    /** @var list<array{level: string, message: string}> */
    private array $logs = [];

    /**
     * @throws \InvalidArgumentException if $fn cannot be serialized
     */
    public function __construct(
        \Closure $fn,
        string $bootstrapScript,
        string $autoloadPath,
        private readonly float $defaultTimeout,
    ) {
        $this->input = new InputStream();

        try {
            $serializedClosure = base64_encode(\Opis\Closure\serialize($fn));
        } catch (\Throwable $e) {
            throw new \InvalidArgumentException(
                'Failed to serialize worker closure. '
                .'Captured variables must be serializable — bootstrap connections and resources inside the closure.',
                previous: $e,
            );
        }

        $this->process = new Process(
            command: [PHP_BINARY, $bootstrapScript],
            env: ['ULTRA_AUTOLOAD' => $autoloadPath],
            input: $this->input,
        );
        $this->process->setTimeout(null); // All timeouts are managed here, not by Symfony.
        $this->process->start();

        // Send the closure as the first message so the bootstrap can unserialize it.
        $this->write(new InitMessage($serializedClosure));
    }

    /**
     * Block until the named checkpoint arrives or timeout elapses.
     *
     * @throws WorkerDiedException          if the worker process exits before hitting the checkpoint
     * @throws ConcurrentTestTimeoutException if the timeout elapses first
     */
    public function awaitCheckpoint(string $name, ?float $timeout = null): Checkpoint
    {
        $timeout ??= $this->defaultTimeout;
        $deadline = microtime(true) + $timeout;

        while (microtime(true) < $deadline) {
            $this->pump();

            if ($this->checkpointQueues[$name] ?? [] !== []) {
                return array_shift($this->checkpointQueues[$name]);
            }

            if (!$this->process->isRunning()) {
                $this->pump(); // drain any final output
                if ($this->checkpointQueues[$name] ?? [] !== []) {
                    return array_shift($this->checkpointQueues[$name]);
                }
                throw new WorkerDiedException($this->workerDiedMessage(
                    sprintf('before reaching checkpoint "%s"', $name),
                    $this->outcome,
                ));
            }

            usleep(1_000); // 1 ms — keeps detection latency low
        }

        throw new ConcurrentTestTimeoutException(sprintf(
            'Timed out after %.1fs waiting for checkpoint "%s". Worker is %s. Stderr: %s',
            $timeout,
            $name,
            $this->process->isRunning() ? 'running' : 'stopped',
            $this->stderrBuffer ?: '(empty)',
        ));
    }

    /**
     * Send a release signal for a named checkpoint.
     * The child's checkpoint() call returns $inject.
     */
    public function release(string $name, mixed $inject = null): void
    {
        $this->write(new \Ultra\Testing\Concurrent\Protocol\ReleaseMessage($name, $inject));
    }

    /**
     * Abort the worker: send the abort message then force-kill after 0.5 s.
     */
    public function abort(): void
    {
        try {
            $this->write(new AbortMessage());
        } catch (\Throwable) {
            // Worker may already be dead.
        }
        $this->process->stop(0.5);
    }

    /**
     * Block until the worker exits and return its Outcome.
     *
     * @throws WorkerDiedException          if the worker exits without emitting a result
     * @throws ConcurrentTestTimeoutException if the timeout elapses
     */
    public function join(?float $timeout = null): Outcome
    {
        $timeout ??= $this->defaultTimeout;
        $deadline = microtime(true) + $timeout;

        while (microtime(true) < $deadline) {
            $this->pump();

            if ($this->outcome !== null) {
                return $this->outcome;
            }

            if (!$this->process->isRunning()) {
                $this->pump();
                if ($this->outcome !== null) {
                    return $this->outcome;
                }
                throw new WorkerDiedException($this->workerDiedMessage('without emitting a result message'));
            }

            usleep(1_000);
        }

        throw new ConcurrentTestTimeoutException(sprintf(
            'Timed out after %.1fs waiting for worker to finish. Stderr: %s',
            $timeout,
            $this->stderrBuffer ?: '(empty)',
        ));
    }

    /** Non-blocking: returns null if the worker has not finished yet. */
    public function outcome(): ?Outcome
    {
        $this->pump();

        return $this->outcome;
    }

    /** @return list<array{level: string, message: string}> */
    public function logs(): array
    {
        return $this->logs;
    }

    /** The full captured stderr output of the child process. */
    public function stderr(): string
    {
        $this->pump();

        return $this->stderrBuffer;
    }

    /** Force-stop the worker. Safe to call even if already stopped. */
    public function stop(): void
    {
        if (!$this->input->isClosed()) {
            $this->input->close();
        }
        if ($this->process->isRunning()) {
            $this->process->stop(0.1);
        }
        $this->pump(); // drain final output
    }

    /** Read all available stdout/stderr and parse any complete JSON lines. */
    public function pump(): void
    {
        $chunk = $this->process->getIncrementalOutput();
        if ($chunk !== '') {
            $this->stdoutRemainder .= $chunk;
            $this->parseLines();
        }

        $errChunk = $this->process->getIncrementalErrorOutput();
        if ($errChunk !== '') {
            $this->stderrBuffer .= $errChunk;
        }
    }

    private function parseLines(): void
    {
        while (false !== $pos = strpos($this->stdoutRemainder, "\n")) {
            $line = substr($this->stdoutRemainder, 0, $pos);
            $this->stdoutRemainder = substr($this->stdoutRemainder, $pos + 1);

            $line = trim($line);
            if ($line === '') {
                continue;
            }

            try {
                $message = Codec::decode($line);
            } catch (ProtocolException $e) {
                $this->process->stop(0.1);
                throw new ProtocolException(
                    sprintf('Protocol error from worker: %s', $e->getMessage()),
                    previous: $e,
                );
            }

            $this->route($message);
        }
    }

    private function route(Message $message): void
    {
        match (true) {
            $message instanceof ReadyMessage => null, // internal; not surfaced to callers
            $message instanceof CheckpointMessage => $this->checkpointQueues[$message->name][] = new Checkpoint($message->name, $message->payload),
            $message instanceof ResultMessage => $this->outcome = $this->decodeResult($message),
            $message instanceof ExceptionMessage => $this->outcome = $this->decodeException($message),
            $message instanceof LogMessage => $this->logs[] = ['level' => $message->level, 'message' => $message->message],
            default => null, // silently ignore any master→child messages echoed back
        };
    }

    private function decodeResult(ResultMessage $message): Outcome
    {
        $raw = base64_decode($message->value, true);
        if ($raw === false) {
            return Outcome::success(null);
        }
        try {
            return Outcome::success(unserialize($raw));
        } catch (\Throwable) {
            return Outcome::success(null);
        }
    }

    private function decodeException(ExceptionMessage $message): Outcome
    {
        if ($message->serialized !== null) {
            $raw = base64_decode($message->serialized, true);
            if ($raw !== false) {
                try {
                    $exception = unserialize($raw);
                    if ($exception instanceof \Throwable) {
                        return Outcome::failure($exception);
                    }
                } catch (\Throwable) {
                    // Fall through to synthetic exception.
                }
            }
        }

        $synthetic = new \RuntimeException(sprintf('[%s] %s', $message->class, $message->message));

        return Outcome::failureFromDescription($message->class, $message->message, $synthetic);
    }

    private function write(Message $message): void
    {
        $this->input->write(Codec::encode($message));
    }

    private function workerDiedMessage(string $context, ?Outcome $outcome = null): string
    {
        $exceptionDetail = '';
        if ($outcome !== null && $outcome->isFailure()) {
            $exceptionDetail = sprintf(
                ' Exception thrown in closure: [%s] %s.',
                $outcome->exceptionClass(),
                $outcome->exceptionMessage(),
            );
        }

        return sprintf(
            'Worker died (exit code: %d) %s.%s Stderr: %s',
            $this->process->getExitCode() ?? -1,
            $context,
            $exceptionDetail,
            $this->stderrBuffer ?: '(empty)',
        );
    }
}
