<?php

declare(strict_types=1);

namespace Ultra\StateStore\Tests\Support;

use PHPUnit\Framework\TestCase;
use Ultra\MappedTable\InMemoryRowDatabase;
use Ultra\MappedTable\MappedRowSet;
use Ultra\StateStore\StateStore;
use Ultra\StateStore\StateTableRegistry;

require_once __DIR__.'/States.php';

/** Sets up mapped states and transaction boundaries; assertions remain in the suites. */
abstract class StateStoreTestCase extends TestCase
{
    /** @var list<StateStoreFixture> */
    private array $fixtures = [];

    public static function backends(): iterable
    {
        yield 'in memory' => ['memory'];
        yield 'Postgres' => ['postgres'];
    }

    /** @param list<class-string> $stateClasses */
    protected function stateFixture(string $backend, array $stateClasses): StateStoreFixture
    {
        $fixture = StateStoreFixture::forBackend($backend, $stateClasses);
        $this->fixtures[] = $fixture;

        return $fixture;
    }

    /**
     * @param list<class-string> $stateClasses
     * @return array{InMemoryRowDatabase, MappedRowSet, StateStore, StateTableRegistry}
     */
    protected function newStore(array $stateClasses): array
    {
        $fixture = $this->stateFixture('memory', $stateClasses);

        return [$fixture->storage, $fixture->registry->tableFor($fixture->metadata->metadataFor($stateClasses[0])), $fixture->store, $fixture->registry];
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

    protected function tearDown(): void
    {
        try {
            foreach (array_reverse($this->fixtures) as $fixture) {
                $fixture->close();
            }
        } finally {
            $this->fixtures = [];
            parent::tearDown();
        }
    }
}
