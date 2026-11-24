<?php

declare(strict_types=1);

namespace Ultra\StateStore\Tests\Support;

use Doctrine\DBAL\Connection;
use PHPUnit\Framework\TestCase;
use Ultra\MappedTable\InMemoryMappedTableAdapter;
use Ultra\MappedTable\InMemoryRowDatabase;
use Ultra\MappedTable\MappedTableUnitOfWork;
use Ultra\MappedTable\PostgresMappedTableAdapter;
use Ultra\MappedTable\QueryGuardDefinition;
use Ultra\Mapper\UltraNormalizer;
use Ultra\Mapper_Compiler\NormalizationShapeAnalyzer;
use Ultra\StateStore\Attribute\StateCollection;
use Ultra\StateStore\Attribute\StateId;
use Ultra\StateStore\StateCollectionMetadata;
use Ultra\StateStore\StateMetadataFactory;
use Ultra\StateStore\StateMetadataRegistry;
use Ultra\StateStore\StateRowSets;
use Ultra\StateStore\StateStore;
use Ultra\Testing\PostgresConnectionSupport;

require_once __DIR__ . '/States.php';

/** Shared object mappings over memory or an isolated Postgres schema, with immediate foreign keys. */
final class StateStoreFixture
{
    public readonly StateMetadataRegistry $metadata;
    public readonly StateStore $store;
    public readonly MappedTableUnitOfWork $unitOfWork;
    public readonly StateRowSets $rowSets;
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
    public function __construct(
        public readonly Connection|InMemoryRowDatabase $storage,
        array $stateClasses,
    ) {
        if ($storage instanceof Connection) {
            $this->schema = 'ultra_state_store_' . bin2hex(random_bytes(4));
            $this->previousSearchPath = $storage->fetchOne('SHOW search_path');
            $storage->executeStatement('CREATE SCHEMA ' . $this->schema);
            $storage->executeStatement('SET search_path TO ' . $this->schema);
        }

        $analyzer = new NormalizationShapeAnalyzer();
        $shapes = [];
        $pending = $stateClasses;
        while ([] !== $pending) {
            $class = array_pop($pending);
            if (isset($shapes[$class])) {
                continue;
            }
            foreach (new \ReflectionClass($class)->getProperties() as $property) {
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
            $factory->create(...),
            $stateClasses,
        )));
        if ($storage instanceof Connection) {
            $statements = [
                'orders' => 'CREATE TABLE orders (order_id TEXT PRIMARY KEY, status TEXT, note TEXT, shipping_street TEXT, shipping_zip TEXT)',
                'order_lines' => 'CREATE TABLE order_lines (order_id TEXT REFERENCES orders (order_id), line_id TEXT, sku TEXT, quantity INT, PRIMARY KEY (order_id, line_id))',
                'composite_orders' => 'CREATE TABLE composite_orders (tenant_id TEXT, order_number TEXT, PRIMARY KEY (tenant_id, order_number))',
                'composite_order_lines' => 'CREATE TABLE composite_order_lines (tenant_id TEXT, order_number TEXT, line_sku TEXT, line_variant TEXT, quantity INT, PRIMARY KEY (tenant_id, order_number, line_sku, line_variant), FOREIGN KEY (tenant_id, order_number) REFERENCES composite_orders (tenant_id, order_number))',
                'counters' => 'CREATE TABLE counters (id INT PRIMARY KEY, count INT)',
            ];
            foreach (array_intersect_key($statements, $this->requiredTables($stateClasses)) as $statement) {
                $storage->executeStatement($statement);
            }
        }
        [$this->store, $this->unitOfWork, $this->rowSets] = $this->newStore($stateClasses);
    }

    /**
     * @param list<class-string> $stateClasses
     * @param list<QueryGuardDefinition> $guards
     * @return array{StateStore, MappedTableUnitOfWork, StateRowSets}
     */
    public function newStore(
        array $stateClasses,
        array $guards = [],
        Connection|InMemoryRowDatabase|null $storage = null,
    ): array {
        $storage ??= $this->storage;
        $factory = $storage instanceof Connection
            ? new PostgresMappedTableAdapter($storage)
            : new InMemoryMappedTableAdapter($storage);

        $required = $this->requiredTables($stateClasses);
        $rowSets = [];
        $tables = [];
        $schema = StateStoreSchema::definition();
        foreach ($schema->tables as $table) {
            if (!isset($required[$table->name])) {
                continue;
            }
            $rowSet = StateStoreSchema::selection($table->id)->compileFor($factory, $schema)();
            $rowSets[$table->id] = $rowSet;
            $tables[$table->name] = $rowSet;
        }
        $unitOfWork = new MappedTableUnitOfWork($rowSets, $factory, queryGuards: $guards);
        $bindings = new StateRowSets($tables, $unitOfWork);

        return [new StateStore($this->metadata, $bindings, new UltraNormalizer()), $unitOfWork, $bindings];
    }

    /**
     * @param list<class-string> $stateClasses
     * @return array<string, true>
     */
    private function requiredTables(array $stateClasses): array
    {
        $required = [];
        foreach ($stateClasses as $class) {
            $metadata = $this->metadata->metadataFor($class);
            $required[$metadata->tableName] = true;
            foreach ($metadata->collections as $collection) {
                $required[$collection->tableName] = true;
            }
        }

        return $required;
    }

    public function flush(?MappedTableUnitOfWork $unitOfWork = null): void
    {
        $this->storage->transactional(($unitOfWork ?? $this->unitOfWork)->flush(...));
    }

    /** @return list<\Ultra\MappedTable\MappedRow> */
    public function collectionRows(StateCollectionMetadata $collection, mixed $normalizedId): array
    {
        $factory = $this->storage instanceof Connection
            ? new PostgresMappedTableAdapter($this->storage)
            : new InMemoryMappedTableAdapter($this->storage);
        $rows = StateStoreSchema::selection($collection->tableName)->compileFor(
            $factory,
            StateStoreSchema::definition(),
        )();

        return $rows->rowsForOwner($collection->ownerKey($normalizedId));
    }

    public function close(): void
    {
        if ($this->storage instanceof Connection) {
            PostgresConnectionSupport::resetSharedConnection($this->storage);
            $this->storage->executeStatement("SELECT set_config('search_path', ?, false)", [$this->previousSearchPath]);
            $this->storage->executeStatement('DROP SCHEMA ' . $this->schema . ' CASCADE');
        } elseif ($this->storage->isTransactionActive()) {
            $this->storage->rollBack();
        }
    }
}
