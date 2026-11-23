<?php

declare(strict_types=1);

namespace Ultra\MappedTable\Tests;

use Doctrine\DBAL\Connection;
use PHPUnit\Framework\Attributes\DataProvider;
use Ultra\MappedTable\MappedKey;
use Ultra\MappedTable\MappedRow;
use Ultra\MappedTable\Tests\Support\RowSetTestCase;
use Ultra\UnitOfWork\DbalTransactionManager;
use Ultra\UnitOfWork\Exception\ConcurrencyException;
use Ultra\UnitOfWork\TransactionalUnitOfWorkRunner;

final class RewriteScenariosTest extends RowSetTestCase
{
    public static function scenarios(): iterable
    {
        $file = dirname(__DIR__, 4) . '/docs/research/mapped-table-rewrite/scenarios.json';
        $scenarios = json_decode((string) file_get_contents($file), true, flags: JSON_THROW_ON_ERROR);
        foreach ($scenarios as $name => $steps) {
            foreach (self::backends() as [$backend]) {
                yield "$name ($backend)" => [$backend, $steps];
            }
        }
    }

    #[DataProvider('scenarios')]
    public function test_transfer_scenarios_match_the_reference_implementation(string $backend, array $steps): void
    {
        [$fixture, $rows] = $this->ownedRows($backend);
        $storage = $fixture->storage;
        $unit = $fixture->unit($rows);
        $runner = new TransactionalUnitOfWorkRunner(
            $unit,
            $storage instanceof Connection ? new DbalTransactionManager($storage) : $storage,
        );

        foreach ($steps as $index => $step) {
            $operation = $step['op'];
            $address = $step['target'] ?? '';
            switch ($operation) {
                case 'seed':
                    $fixture->seed([...self::key($address), ...($step['values'] + ['status' => null, 'note' => null])]);
                    break;
                case 'change':
                    $rows->withKey(self::key($address))->setColumns($step['values']);
                    break;
                case 'upsert':
                    $rows->withKey(self::key($address))->upsert()->setColumns($step['values']);
                    break;
                case 'reconcile':
                    $desired = [];
                    foreach ($step['rows'] as $id => $values) {
                        $desired[] = new MappedRow(['tenant' => 'shop', 'owner' => $address, 'id' => $id], $values);
                    }
                    $rows->reconcileForOwner(['tenant' => 'shop', 'owner' => $address], $desired);
                    break;
                case 'flush':
                    $outcome = 'ok';
                    try {
                        $runner->run(static function (): void {});
                    } catch (ConcurrencyException) {
                        $outcome = 'conflict';
                    }
                    static::assertSame($step['outcome'], $outcome, "Step $index");
                    static::assertFalse($unit->hasPendingChanges(), "Step $index left pending work");
                    break;
                default:
                    $owner = ['tenant' => 'shop', 'owner' => $address];
                    $actual = match ($operation) {
                        'read' => $rows->withKey(self::key($address))->get($step['columns']),
                        'observe' => $rows->withKey(self::key($address))->observe($step['columns']),
                        'stored' => $fixture->stored(new MappedKey(self::key($address)), $step['columns']),
                        'keys' => array_map(
                            static fn(MappedRow $row): int|string => $row->key->value('id'),
                            $rows->rowsForOwner($owner, []),
                        ),
                        'observeKeys' => array_map(static fn(MappedKey $key) => $key->value(
                            'id',
                        ), $rows->observeKeysForOwner($owner)),
                        default => throw new \LogicException('Unknown transfer scenario operation ' . $operation),
                    };
                    $expected = $step['returns'];
                    if ('keys' === $operation || 'observeKeys' === $operation) {
                        sort($actual, SORT_STRING);
                    } elseif (is_array($expected) && is_array($actual)) {
                        ksort($expected, SORT_STRING);
                        ksort($actual, SORT_STRING);
                    }
                    static::assertSame($expected, $actual, "Step $index ($operation)");
            }
        }
    }

    /** @return array{tenant: string, owner: string, id: string} */
    private static function key(string $address): array
    {
        [$owner, $id] = explode('/', $address, 2);

        return ['tenant' => 'shop', 'owner' => $owner, 'id' => $id];
    }
}
