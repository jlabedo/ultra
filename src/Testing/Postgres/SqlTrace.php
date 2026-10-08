<?php

declare(strict_types=1);

namespace Ultra\Testing;

use Doctrine\DBAL\ParameterType;

/** Opt-in driver observations for development scenarios, independent of a storage model. */
final class SqlTrace
{
    /** @var list<SqlTraceEntry> */
    private array $entries = [];
    private string $phase = 'scenario';

    /** @return list<SqlTraceEntry> */
    public function entries(): array
    {
        return $this->entries;
    }

    public function clear(): void
    {
        $this->entries = [];
    }

    /**
     * @template T
     * @param callable(): T $work
     * @return T
     */
    public function during(string $phase, callable $work): mixed
    {
        $previous = $this->phase;
        $this->phase = $phase;
        try {
            return $work();
        } finally {
            $this->phase = $previous;
        }
    }

    /**
     * @internal Called by SqlTraceMiddleware; preserves the driver's result or exception.
     * @template T
     * @param array<array-key, mixed> $parameters
     * @param array<array-key, ParameterType> $types
     * @param callable(): T $work
     * @return T
     */
    public function record(string $operation, ?string $sql, array $parameters, array $types, callable $work): mixed
    {
        $started = hrtime(true);
        $error = null;
        try {
            return $work();
        } catch (\Throwable $exception) {
            $error = $exception;
            throw $exception;
        } finally {
            // Successful preparation is not an execution. Retain a failure so its SQL is still inspectable.
            if ('prepare' !== $operation || null !== $error) {
                $this->entries[] = new SqlTraceEntry(
                    $this->phase,
                    $operation,
                    $sql,
                    $parameters,
                    $types,
                    (hrtime(true) - $started) / 1_000_000,
                    null === $error ? null : $error::class,
                    $error?->getMessage(),
                );
            }
        }
    }
}
