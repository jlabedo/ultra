<?php

declare(strict_types=1);

namespace Ultra\StateStore\Tests\Support;

use PHPUnit\Framework\TestCase;
use Ultra\MappedTable\InMemoryRowDatabase;
use Ultra\MappedTable\MappedRowSet;
use Ultra\Mapper\UltraNormalizer;
use Ultra\Mapper_Compiler\NormalizationShapeAnalyzer;
use Ultra\StateStore\Attribute\StateCollection;
use Ultra\StateStore\Attribute\StateId;
use Ultra\StateStore\StateMetadataFactory;
use Ultra\StateStore\StateMetadataRegistry;
use Ultra\StateStore\InMemoryGuardLockAdapter;
use Ultra\StateStore\StateStore;
use Ultra\StateStore\StateTableRegistry;

require_once __DIR__.'/States.php';

/** Sets up mapped states and transaction boundaries; assertions remain in the suites. */
abstract class StateStoreTestCase extends TestCase
{
    /**
     * @param list<class-string> $stateClasses
     * @return array{InMemoryRowDatabase, MappedRowSet, StateStore, StateTableRegistry}
     */
    protected function newStore(array $stateClasses): array
    {
        $database = new InMemoryRowDatabase();
        $adapters = new \Ultra\MappedTable\InMemoryMappedRowSetAdapterFactory($database);
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
        $metadata = [];
        $tables = [];
        foreach ($stateClasses as $class) {
            $state = $factory->create($class);
            $metadata[$class] = $state->toArray();
            $tables[$state->tableKey] ??= new MappedRowSet($adapters->create($state->tableName, $state->keyColumns()));
        }
        $registry = new StateTableRegistry($tables, $adapters, guardLockAdapter: new InMemoryGuardLockAdapter($database));

        return [$database, reset($tables), new StateStore(new StateMetadataRegistry($metadata), $registry, new UltraNormalizer()), $registry];
    }

    protected function flush(InMemoryRowDatabase $database, \Ultra\UnitOfWork\UnitOfWork $unitOfWork): void
    {
        $database->beginTransaction();
        try {
            $unitOfWork->flush();
            $database->commit();
        } catch (\Throwable $exception) {
            if ($database->isTransactionActive()) {
                $database->rollBack();
            }
            throw $exception;
        }
    }
}
