<?php

declare(strict_types=1);

namespace Ultra\Testing\Concurrent;

use Ultra\Testing\Concurrent\Exception\ConcurrentTestTimeoutException;

/**
 * Orchestrator for spawning and coordinating multiple concurrent worker processes.
 *
 * Each worker runs a serialized closure in a subprocess and communicates via
 * newline-delimited JSON on stdin/stdout. The parent process controls checkpoints
 * and timing through this facade.
 *
 * PostgreSQL connections and other non-serializable resources must be opened
 * inside each closure, not captured from the outer scope.
 */
final class Cluster
{
    /** @var list<Worker> */
    private array $workers = [];

    private readonly string $autoloadPath;
    private readonly string $bootstrapScript;

    public function __construct(
        private readonly float $timeout = 5.0,
        ?string $autoloadPath = null,
    ) {
        $this->autoloadPath = $autoloadPath ?? self::detectAutoloadPath();
        $this->bootstrapScript = __DIR__.'/Internal/worker_bootstrap.php';
    }

    /** Spawn a new worker process that will execute $fn in a subprocess. */
    public function spawn(\Closure $fn): Worker
    {
        $worker = new Worker(
            fn: $fn,
            bootstrapScript: $this->bootstrapScript,
            autoloadPath: $this->autoloadPath,
            defaultTimeout: $this->timeout,
        );
        $this->workers[] = $worker;

        return $worker;
    }

    /**
     * Release the named checkpoint on every worker that is currently frozen at it.
     * Workers that have not yet reached the checkpoint will receive the release
     * message when they do.
     */
    public function releaseAll(string $name, mixed $inject = null): void
    {
        foreach ($this->workers as $worker) {
            $worker->release($name, $inject);
        }
    }

    /**
     * Wait for every worker to finish and return their outcomes in spawn order.
     *
     * @return list<Outcome>
     * @throws ConcurrentTestTimeoutException if any worker does not finish within the timeout
     */
    public function joinAll(?float $timeout = null): array
    {
        $timeout ??= $this->timeout;

        $outcomes = [];
        foreach ($this->workers as $worker) {
            $outcomes[] = $worker->join($timeout);
        }

        return $outcomes;
    }

    /** Force-kill all still-running workers. Prevents zombies on test failure. */
    public function __destruct()
    {
        foreach ($this->workers as $worker) {
            $worker->stop();
        }
    }

    private static function detectAutoloadPath(): string
    {
        // Find the Composer ClassLoader instance already registered in this process.
        foreach (spl_autoload_functions() as $loader) {
            if (is_array($loader) && $loader[0] instanceof \Composer\Autoload\ClassLoader) {
                $ref = new \ReflectionClass($loader[0]);
                // ClassLoader lives at vendor/composer/ClassLoader.php; autoload.php is two levels up.
                $candidate = dirname($ref->getFileName(), 2).'/autoload.php';
                if (file_exists($candidate)) {
                    return $candidate;
                }
            }
        }

        throw new \RuntimeException(
            'Cannot auto-detect vendor/autoload.php. Pass it explicitly: new Cluster(autoloadPath: "/path/to/vendor/autoload.php").',
        );
    }
}
