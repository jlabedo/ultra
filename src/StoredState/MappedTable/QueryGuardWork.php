<?php

declare(strict_types=1);

namespace Ultra\MappedTable;

use Ultra\MappedTable\Exception\PendingQueryChangesException;
use Ultra\MappedTable\Exception\UnknownQueryGuardException;
use Ultra\UnitOfWork\Exception\ConcurrencyException;

/** @internal Pending reader requests and replay for one unit of work. */
final class QueryGuardWork
{
    /** @var array<array-key, array<array-key, GuardLockMode>> */
    private array $locks = [];

    /** @var list<array{name: string, value: int|string, query: \Closure(): mixed, expected: mixed}> */
    private array $observations = [];

    private bool $replaying = false;

    public function __construct(
        private readonly QueryGuardRules $rules,
        private readonly GuardLockAdapter $adapter,
    ) {}

    public function assertKnown(string $name): void
    {
        if (!isset($this->rules->byName[$name])) {
            throw new UnknownQueryGuardException($name);
        }
    }

    public function lock(string $name, int|string $value): void
    {
        $this->assertCollecting();
        $this->assertKnown($name);
        $this->locks[$name][(string) $value] = GuardLockMode::SHARED;
    }

    /**
     * @template T
     * @param callable(): T $query
     * @param array<class-string<MappedRowSetDefinition>, MappedRowSet> $rowSets
     * @return T
     */
    public function observe(string $name, int|string $value, callable $query, array $rowSets): mixed
    {
        $this->assertCollecting();
        $this->assertKnown($name);
        $fingerprint = (string) $value;
        foreach ($this->rules->byName[$name] as $definition => $indexes) {
            $rowSet = $rowSets[$definition];
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
        self::assertStable($result);
        $this->locks[$name][$fingerprint] = GuardLockMode::SHARED;
        $this->observations[] = ['name' => $name, 'value' => $value, 'query' => $query(...), 'expected' => $result];

        return $result;
    }

    /**
     * @param array<class-string<MappedRowSetDefinition>, RowSetUpdatePlan> $plans
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

    /**
     * @param array<array-key, array<array-key, GuardLockMode>> $requests
     * @return iterable<class-string<MappedRowSetDefinition>, true>
     */
    public function watchedRowSets(array $requests): iterable
    {
        foreach (array_keys($requests) as $name) {
            foreach (array_keys($this->rules->byName[$name]) as $definition) {
                yield $definition => true;
            }
        }
    }

    /** @param array<array-key, array<array-key, GuardLockMode>> $requests */
    public function replay(array $requests, ?object $rowSetResource): void
    {
        $this->assertCollecting();
        if ([] === $requests) {
            return;
        }

        $resource = $this->adapter->activeTransactionResource();
        if (null !== $rowSetResource && $rowSetResource !== $resource) {
            throw new \LogicException('Query guards and mapped row sets must use the same transaction resource.');
        }

        foreach ($this->rules->namespaces as $name => $namespace) {
            $values = $requests[$name] ?? [];
            ksort($values, SORT_STRING);
            foreach ($values as $value => $mode) {
                $this->adapter->acquire($namespace, (string) $value, $mode);
            }
        }

        $this->replaying = true;
        try {
            foreach ($this->observations as $observation) {
                if ($observation['query']() !== $observation['expected']) {
                    throw new ConcurrencyException(sprintf(
                        'Query expectation failed for "%s(%s)".',
                        $observation['name'],
                        $observation['value'],
                    ));
                }
            }
        } finally {
            $this->replaying = false;
        }
    }

    public function hasPendingChanges(): bool
    {
        return [] !== $this->locks;
    }

    public function clear(): void
    {
        $this->assertCollecting();
        $this->locks = [];
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
