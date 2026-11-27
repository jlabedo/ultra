<?php

declare(strict_types=1);

namespace Ultra\Projection\Tests\Benchmark;

use Doctrine\DBAL\Connection;
use Ultra\Events\Attribute\Event as EventAttribute;
use Ultra\EventStore\Event;
use Ultra\Mapper\UltraNormalizer;
use Ultra\Projection\Attribute\Projection;
use Ultra\Projection\Attribute\ProjectionFlush;
use Ultra\Projection\Attribute\ProjectionHandler;
use Ultra\Projection\ProjectionRuntime;

/** Inventory events and read models for an isolated benchmark schema. */
final class AsyncProjectionWorkload
{
    private const string IGNORED = 'benchmark.stock.audited';

    /** @var list<ProjectionRuntime> */
    public readonly array $runtimes;
    private readonly WarehouseStockProjection $warehouse;
    private readonly SupplierStockProjection $supplier;

    public function __construct(
        private readonly Connection $connection,
        UltraNormalizer $normalizer,
    ) {
        foreach ([ItemStockProjection::TABLE, SupplierStockProjection::TABLE] as $table) {
            $connection->executeStatement(sprintf(
                'CREATE TABLE %s (partition_id TEXT PRIMARY KEY, units BIGINT NOT NULL, event_count BIGINT NOT NULL)',
                $table,
            ));
        }

        $this->warehouse = new WarehouseStockProjection();
        $this->supplier = new SupplierStockProjection($connection);
        $this->runtimes = [
            new ProjectionRuntime($this->warehouse, $normalizer),
            new ProjectionRuntime(new ItemStockProjection($connection), $normalizer),
            new ProjectionRuntime($this->supplier, $normalizer),
        ];
    }

    /** @return list<Event> */
    public function events(int $count, bool $matched = true): array
    {
        $events = [];
        for ($i = 0; $i < $count; ++$i) {
            $domainIds = [
                'warehouseId' => 'warehouse-' . ($i % 61),
                'itemId' => 'item-' . ($i % 31),
                'supplierId' => 'supplier-' . ($i % 11),
            ];
            $eventType = $matched
                ? match ($i % 4) {
                    0 => StockReceived::EVENT_TYPE,
                    1 => StockReserved::EVENT_TYPE,
                    2 => StockReturned::EVENT_TYPE,
                    default => self::IGNORED,
                }
                : self::IGNORED;

            $events[] = new Event($eventType, [...$domainIds, 'units' => 1 + ($i % 13)], $domainIds);
        }

        return $events;
    }

    public function assertReadModels(int $expectedEvents): void
    {
        $warehouseMovements = [];
        $itemMovements = [];
        $supplierMovements = [];
        foreach ($this->events($expectedEvents) as $event) {
            $units = $event->payload['units'];
            assert(is_int($units));
            $warehouse = $event->domainIds['warehouseId'];
            $item = $event->domainIds['itemId'];
            $supplier = $event->domainIds['supplierId'];
            switch ($event->eventType) {
                case StockReceived::EVENT_TYPE:
                    $warehouseMovements[$warehouse][] = $units;
                    $itemMovements[$item][] = $units;
                    $supplierMovements[$supplier][] = $units;
                    break;
                case StockReserved::EVENT_TYPE:
                    $warehouseMovements[$warehouse][] = -$units;
                    $itemMovements[$item][] = -$units;
                    break;
                case StockReturned::EVENT_TYPE:
                    $warehouseMovements[$warehouse][] = $units;
                    $supplierMovements[$supplier][] = $units;
                    break;
            }
        }

        self::assertTotals('warehouse', $warehouseMovements, $this->warehouse->totals);
        self::assertTotals('item', $itemMovements, $this->readTable(ItemStockProjection::TABLE));
        self::assertTotals('supplier', $supplierMovements, $this->readTable(SupplierStockProjection::TABLE));
        if ($this->supplier->hasPendingChanges()) {
            throw new \RuntimeException('The supplier projection still has unflushed movements.');
        }
    }

    public function assertUntouched(): void
    {
        $this->assertReadModels(0);
    }

    /** @return array<string, array{units: int, event_count: int}> */
    private function readTable(string $table): array
    {
        $totals = [];
        foreach ($this->connection->fetchAllAssociative('SELECT partition_id, units, event_count FROM '
        . $table) as $row) {
            $totals[(string) $row['partition_id']] = [
                'units' => (int) $row['units'],
                'event_count' => (int) $row['event_count'],
            ];
        }

        return $totals;
    }

    /**
     * @param array<string, list<int>> $movements
     * @param array<string, array{units: int, event_count: int}> $actual
     */
    private static function assertTotals(string $name, array $movements, array $actual): void
    {
        $expected = [];
        foreach ($movements as $partition => $units) {
            $expected[$partition] = ['units' => array_sum($units), 'event_count' => count($units)];
        }
        ksort($expected);
        ksort($actual);
        if ($actual !== $expected) {
            throw new \RuntimeException(sprintf('The %s projection produced unexpected inventory totals.', $name));
        }
    }
}

#[EventAttribute(StockReceived::EVENT_TYPE)]
final readonly class StockReceived
{
    public const string EVENT_TYPE = 'benchmark.stock.received';

    public function __construct(
        public string $warehouseId,
        public string $itemId,
        public string $supplierId,
        public int $units,
    ) {}
}

#[EventAttribute(StockReserved::EVENT_TYPE)]
final readonly class StockReserved
{
    public const string EVENT_TYPE = 'benchmark.stock.reserved';

    public function __construct(
        public string $warehouseId,
        public string $itemId,
        public string $supplierId,
        public int $units,
    ) {}
}

#[EventAttribute(StockReturned::EVENT_TYPE)]
final readonly class StockReturned
{
    public const string EVENT_TYPE = 'benchmark.stock.returned';

    public function __construct(
        public string $warehouseId,
        public string $itemId,
        public string $supplierId,
        public int $units,
    ) {}
}

#[Projection(name: 'benchmark_warehouse_stock', partitionBy: 'warehouseId')]
final class WarehouseStockProjection
{
    /** @var array<string, array{units: int, event_count: int}> */
    public array $totals = [];

    #[ProjectionHandler]
    public function onReceived(StockReceived $event): void
    {
        $this->record($event->warehouseId, $event->units);
    }

    #[ProjectionHandler]
    public function onReserved(StockReserved $event): void
    {
        $this->record($event->warehouseId, -$event->units);
    }

    #[ProjectionHandler]
    public function onReturned(StockReturned $event): void
    {
        $this->record($event->warehouseId, $event->units);
    }

    private function record(string $warehouseId, int $units): void
    {
        $this->totals[$warehouseId] = [
            'units' => ($this->totals[$warehouseId]['units'] ?? 0) + $units,
            'event_count' => ($this->totals[$warehouseId]['event_count'] ?? 0) + 1,
        ];
    }
}

#[Projection(name: 'benchmark_item_stock', partitionBy: 'itemId')]
final readonly class ItemStockProjection
{
    public const string TABLE = 'benchmark_item_stock';

    public function __construct(
        private Connection $connection,
    ) {}

    #[ProjectionHandler]
    public function onReceived(StockReceived $event): void
    {
        $this->write($event->itemId, $event->units);
    }

    #[ProjectionHandler]
    public function onReserved(StockReserved $event): void
    {
        $this->write($event->itemId, -$event->units);
    }

    private function write(string $itemId, int $units): void
    {
        $this->connection->executeStatement(
            'INSERT INTO '
            . self::TABLE
            . ' (partition_id, units, event_count) VALUES (?, ?, 1)
             ON CONFLICT (partition_id) DO UPDATE SET
                 units = '
            . self::TABLE
            . '.units + EXCLUDED.units,
                 event_count = '
            . self::TABLE
            . '.event_count + EXCLUDED.event_count',
            [$itemId, $units],
        );
    }
}

#[Projection(name: 'benchmark_supplier_stock', partitionBy: 'supplierId')]
final class SupplierStockProjection
{
    public const string TABLE = 'benchmark_supplier_stock';

    /** @var array<string, array{units: int, event_count: int}> */
    private array $pending = [];

    public function __construct(
        private readonly Connection $connection,
    ) {}

    #[ProjectionHandler]
    public function onReceived(StockReceived $event): void
    {
        $this->record($event->supplierId, $event->units);
    }

    #[ProjectionHandler]
    public function onReturned(StockReturned $event): void
    {
        $this->record($event->supplierId, $event->units);
    }

    #[ProjectionFlush]
    public function flush(): void
    {
        if ([] === $this->pending) {
            return;
        }
        $parameters = [];
        foreach ($this->pending as $supplierId => $delta) {
            array_push($parameters, $supplierId, $delta['units'], $delta['event_count']);
        }
        $rows = implode(', ', array_fill(0, count($this->pending), '(?, ?, ?)'));
        $this->connection->executeStatement(
            'INSERT INTO '
            . self::TABLE
            . ' (partition_id, units, event_count) VALUES '
            . $rows
            . '
             ON CONFLICT (partition_id) DO UPDATE SET
                 units = '
            . self::TABLE
            . '.units + EXCLUDED.units,
                 event_count = '
            . self::TABLE
            . '.event_count + EXCLUDED.event_count',
            $parameters,
        );
        $this->pending = [];
    }

    public function hasPendingChanges(): bool
    {
        return [] !== $this->pending;
    }

    private function record(string $supplierId, int $units): void
    {
        $this->pending[$supplierId] = [
            'units' => ($this->pending[$supplierId]['units'] ?? 0) + $units,
            'event_count' => ($this->pending[$supplierId]['event_count'] ?? 0) + 1,
        ];
    }
}
