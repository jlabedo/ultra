<?php

declare(strict_types=1);

namespace Ultra\StateStore\Tests\Support;

use Doctrine\DBAL\Connection;
use PHPUnit\Framework\TestCase;
use Ultra\MappedTable\InMemoryMappedRowSetAdapterFactory;
use Ultra\MappedTable\InMemoryRowDatabase;
use Ultra\MappedTable\MappedRowSet;
use Ultra\MappedTable\MappedRowSetAdapterFactory;
use Ultra\MappedTable\PostgresMappedRowSetAdapterFactory;
use Ultra\Mapper\UltraNormalizer;
use Ultra\Mapper_Compiler\NormalizationShapeAnalyzer;
use Ultra\StateStore\Attribute\StateCollection;
use Ultra\StateStore\Attribute\StateId;
use Ultra\StateStore\InMemoryGuardLockAdapter;
use Ultra\StateStore\PostgresGuardLockAdapter;
use Ultra\StateStore\StateMetadataFactory;
use Ultra\StateStore\StateMetadataRegistry;
use Ultra\StateStore\StateCollectionMetadata;
use Ultra\StateStore\StateStore;
use Ultra\StateStore\StateTableRegistry;
use Ultra\Testing\PostgresConnectionSupport;

require_once __DIR__.'/States.php';

/** Shared object mappings over memory or an isolated Postgres schema, with immediate foreign keys. */
final class StateStoreFixture
{
    public readonly StateMetadataRegistry $metadata;
    public readonly StateStore $store;
    public readonly StateTableRegistry $registry;
    private readonly MappedRowSetAdapterFactory $adapters;
    private ?string $schema = null;
    private ?string $previousSearchPath = null;

    /** @param list<class-string> $stateClasses */
    public static function forBackend(string $backend, array $stateClasses): self
    {
        if ('postgres' === $backend) {
            $dsn = getenv('ULTRA_TEST_POSTGRES_DSN');
            if (false === $dsn || '' === $dsn) {
                TestCase::markTestSkipped('Set ULTRA_TEST_POSTGRES_DSN to run Postgres state guarantees.');
            }
            $storage = PostgresConnectionSupport::sharedConnection($dsn);
        } else {
            $storage = new InMemoryRowDatabase();
        }

        return new self($storage, $stateClasses);
    }

    /** @param list<class-string> $stateClasses */
    public function __construct(public readonly Connection|InMemoryRowDatabase $storage, array $stateClasses)
    {
        if ($storage instanceof Connection) {
            $this->schema = 'ultra_state_store_'.bin2hex(random_bytes(4));
            $this->previousSearchPath = $storage->fetchOne('SHOW search_path');
            $storage->executeStatement('CREATE SCHEMA '.$this->schema);
            $storage->executeStatement('SET search_path TO '.$this->schema);
            $this->adapters = new PostgresMappedRowSetAdapterFactory($storage);
        } else {
            $this->adapters = new InMemoryMappedRowSetAdapterFactory($storage);
        }

        $analyzer = new NormalizationShapeAnalyzer();
        $shapes = [];
        $pending = $stateClasses;
        while ([] !== $pending) {
            $class = array_pop($pending);
            if (isset($shapes[$class])) {
                continue;
            }
            foreach ((new \ReflectionClass($class))->getProperties() as $property) {
                if ([] !== $property->getAttributes(StateId::class)) {
                    $shapes[$class] = $analyzer->forProperty($property);
                }
                foreach ($property->getAttributes(StateCollection::class) as $attribute) {
                    $pending[] = $attribute->newInstance()->itemClass;
                }
            }
        }
        $factory = new StateMetadataFactory($shapes);
        $this->metadata = new StateMetadataRegistry(array_combine($stateClasses, array_map(
            static fn (string $class): array => $factory->create($class)->toArray(),
            $stateClasses,
        )));
        if ($storage instanceof Connection) {
            $statements = [
                'orders' => 'CREATE TABLE orders (order_id TEXT PRIMARY KEY, status TEXT, note TEXT)',
                'order_lines' => 'CREATE TABLE order_lines (order_id TEXT REFERENCES orders (order_id), line_id TEXT, sku TEXT, quantity INT, PRIMARY KEY (order_id, line_id))',
                'composite_orders' => 'CREATE TABLE composite_orders (tenant_id TEXT, order_number TEXT, PRIMARY KEY (tenant_id, order_number))',
                'composite_order_lines' => 'CREATE TABLE composite_order_lines (tenant_id TEXT, order_number TEXT, line_sku TEXT, line_variant TEXT, quantity INT, PRIMARY KEY (tenant_id, order_number, line_sku, line_variant), FOREIGN KEY (tenant_id, order_number) REFERENCES composite_orders (tenant_id, order_number))',
                'counters' => 'CREATE TABLE counters (id INT PRIMARY KEY, count INT)',
            ];
            $required = [];
            foreach ($stateClasses as $class) {
                $metadata = $this->metadata->metadataFor($class);
                $required[$metadata->tableName] = true;
                foreach ($metadata->collections as $collection) {
                    $required[$collection->tableName] = true;
                }
            }
            foreach (array_intersect_key($statements, $required) as $statement) {
                $storage->executeStatement($statement);
            }
        }
        [$this->store, $this->registry] = $this->newStore($stateClasses);
    }

    /**
     * @param list<class-string> $stateClasses
     * @param array<string, list<array{tableKey: string, keyBy: string, columns: list<string>|null}>> $definitions
     * @return array{StateStore, StateTableRegistry}
     */
    public function newStore(array $stateClasses, array $definitions = [], Connection|InMemoryRowDatabase|null $storage = null): array
    {
        $storage ??= $this->storage;
        $adapters = $storage === $this->storage ? $this->adapters : ($storage instanceof Connection
            ? new PostgresMappedRowSetAdapterFactory($storage)
            : new InMemoryMappedRowSetAdapterFactory($storage));
        $tables = [];
        foreach ($stateClasses as $class) {
            $state = $this->metadata->metadataFor($class);
            $tables[$state->tableKey] ??= new MappedRowSet($adapters->create($state->tableName, $state->keyColumns()));
        }
        $guards = $storage instanceof Connection
            ? new PostgresGuardLockAdapter($storage)
            : new InMemoryGuardLockAdapter($storage);
        $registry = new StateTableRegistry($tables, $adapters, $definitions, $guards);

        return [new StateStore($this->metadata, $registry, new UltraNormalizer()), $registry];
    }

    public function flush(?StateTableRegistry $registry = null): void
    {
        $this->storage->beginTransaction();
        try {
            ($registry ?? $this->registry)->flush();
            $this->storage->commit();
        } catch (\Throwable $exception) {
            $this->storage->rollBack();
            throw $exception;
        }
    }

    /** @return list<\Ultra\MappedTable\MappedRow> */
    public function collectionRows(StateCollectionMetadata $collection, mixed $normalizedId): array
    {
        return $this->adapters->createScoped($collection->tableName, $collection->keyColumns(), $collection->ownerKey($normalizedId))->rows();
    }

    public function close(): void
    {
        if ($this->storage instanceof Connection) {
            PostgresConnectionSupport::resetSharedConnection($this->storage);
            $this->storage->executeStatement("SELECT set_config('search_path', ?, false)", [$this->previousSearchPath]);
            $this->storage->executeStatement('DROP SCHEMA '.$this->schema.' CASCADE');
        } elseif ($this->storage->isTransactionActive()) {
            $this->storage->rollBack();
        }
    }
}
