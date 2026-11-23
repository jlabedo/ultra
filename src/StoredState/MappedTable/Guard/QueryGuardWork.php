<?php

declare(strict_types=1);

namespace Ultra\MappedTable\Guard;

use Ultra\MappedTable\Exception\PendingQueryChangesException;
use Ultra\MappedTable\Exception\UnknownQueryGuardException;
use Ultra\MappedTable\GuardLockAdapter;
use Ultra\MappedTable\GuardLockMode;
use Ultra\MappedTable\MappedKey;
use Ultra\MappedTable\MappedRowSet;
use Ultra\MappedTable\Plan\RowSetUpdatePlan;
use Ultra\MappedTable\QueryGuard;
use Ultra\UnitOfWork\Exception\ConcurrencyException;

/** @internal Pending reader requests and replay for one unit of work. */
final class QueryGuardWork
{
    /** @var array<array-key, array<array-key, GuardLockMode>> */
    private array $locks = [];

    /** @var array<string, array<string, true>> */
    private array $rowLocks = [];

    /** @var list<array{name: ?string, query: \Closure(): mixed, expected: mixed}> */
    private array $observations = [];

    private bool $replaying = false;

    /** @param array<string, MappedRowSet> $rowSets */
    public function __construct(
        private readonly QueryGuardRules $rules,
        private readonly ?GuardLockAdapter $adapter,
        private readonly array $rowSets,
    ) {}

    public function guard(string $name): QueryGuard
    {
        if (!isset($this->rules->byName[$name])) {
            throw new UnknownQueryGuardException($name);
        }

        return new QueryGuard($this, $name);
    }

    public function lock(string $name, int|string $value): void
    {
        $this->assertCollecting();
        $this->locks[$name][(string) $value] = GuardLockMode::SHARED;
    }

    /**
     * @template T
     * @param callable(): T $query
     * @return T
     */
    public function observe(string $name, int|string $value, callable $query): mixed
    {
        $this->assertCollecting();
        $fingerprint = (string) $value;
        foreach ($this->rules->byName[$name] as $definition => $indexes) {
            $rowSet = $this->rowSets[$definition];
            if (!$rowSet->hasPendingChanges()) {
                continue;
            }

            $plan = $rowSet->updatePlan();
            foreach ($indexes as $index) {
                foreach ($this->rules->rules[$index]->affectedValues($plan) as $request) {
                    if ($request['value'] === $fingerprint) {
                        throw new PendingQueryChangesException($name, $value, $definition, $request['key']);
                    }
                }
            }
        }

        $result = $query();
        $this->expectQuery($query, $result, $name . '(' . $value . ')');
        $this->locks[$name][$fingerprint] = GuardLockMode::SHARED;

        return $result;
    }

    public function lockRow(string $table, MappedKey $key): void
    {
        $this->assertCollecting();
        $this->rowLocks[$table][$key->fingerprint()] = true;
    }

    /** @param callable(): mixed $query */
    public function expectQuery(callable $query, mixed $expected, ?string $name = null): void
    {
        $this->assertCollecting();
        self::assertStable($expected);
        $this->observations[] = ['name' => $name, 'query' => $query(...), 'expected' => $expected];
    }

    /**
     * @param array<string, RowSetUpdatePlan> $plans
     * @return array<array-key, array<array-key, GuardLockMode>>
     */
    public function requests(array $plans): array
    {
        $requests = $this->locks;
        foreach ($plans as $definition => $plan) {
            foreach ($this->rules->byRowSet[$definition] ?? [] as $index) {
                $rule = $this->rules->rules[$index];
                foreach ($rule->affectedValues($plan) as $request) {
                    $requests[$rule->name][$request['value']] = GuardLockMode::EXCLUSIVE;
                }
            }
        }

        return $requests;
    }

    /** @param array<array-key, array<array-key, GuardLockMode>> $requests */
    public function replay(array $requests, ?object $rowSetResource): void
    {
        $this->assertCollecting();
        if ([] !== $requests || [] !== $this->rowLocks) {
            $this->acquire($requests, $rowSetResource);
        }

        $this->replaying = true;
        try {
            foreach ($this->observations as $observation) {
                if ($observation['query']() !== $observation['expected']) {
                    throw new ConcurrencyException(sprintf(
                        'Query expectation failed%s.',
                        null === $observation['name'] ? '' : ' for "' . $observation['name'] . '"',
                    ));
                }
            }
        } finally {
            $this->replaying = false;
        }
    }

    /** @param array<array-key, array<array-key, GuardLockMode>> $requests */
    private function acquire(array $requests, ?object $rowSetResource): void
    {
        $adapter = $this->adapter ?? throw new \LogicException('Mapped table locks require a GuardLockAdapter.');
        $resource = $adapter->activeTransactionResource();
        if (null !== $rowSetResource && $rowSetResource !== $resource) {
            throw new \LogicException('Query guards and mapped row sets must use the same transaction resource.');
        }

        foreach ($this->rules->namespaces as $name => $namespace) {
            $values = $requests[$name] ?? [];
            ksort($values, SORT_STRING);
            foreach ($values as $value => $mode) {
                $adapter->acquire($namespace, (string) $value, $mode);
            }
        }
        ksort($this->rowLocks, SORT_STRING);
        foreach ($this->rowLocks as $table => $keys) {
            ksort($keys, SORT_STRING);
            foreach (array_keys($keys) as $key) {
                $adapter->acquire($table . ':manual', $key, GuardLockMode::EXCLUSIVE);
            }
        }
    }

    public function hasPendingChanges(): bool
    {
        return [] !== $this->locks || [] !== $this->rowLocks || [] !== $this->observations;
    }

    public function clear(): void
    {
        $this->assertCollecting();
        $this->locks = [];
        $this->rowLocks = [];
        $this->observations = [];
    }

    private function assertCollecting(): void
    {
        if ($this->replaying) {
            throw new \LogicException('Query guard replay requires read-only callbacks.');
        }
    }

    private static function assertStable(mixed $value): void
    {
        if (is_array($value)) {
            foreach ($value as $key => $item) {
                if (null !== \ReflectionReference::fromArrayElement($value, $key)) {
                    throw new \InvalidArgumentException('Query expectations cannot contain PHP references.');
                }
                self::assertStable($item);
            }

            return;
        }
        if (null !== $value && !is_scalar($value)) {
            throw new \InvalidArgumentException('Query expectations require scalars, null or arrays of these values.');
        }
    }
}
