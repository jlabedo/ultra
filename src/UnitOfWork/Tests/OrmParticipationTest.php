<?php

declare(strict_types=1);

namespace Ultra\UnitOfWork\Tests;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Doctrine\ORM\Configuration;
use Doctrine\ORM\EntityManager;
use Doctrine\ORM\Mapping\Driver\AttributeDriver;
use Doctrine\ORM\Tools\SchemaTool;
use PHPUnit\Framework\Attributes\DataProvider;
use Ultra\UnitOfWork\CompositeUnitOfWork;
use Ultra\UnitOfWork\DbalTransactionManager;
use Ultra\UnitOfWork\OrmUnitOfWork;
use Ultra\UnitOfWork\Tests\Support\ContainerTestCase;
use Ultra\UnitOfWork\Tests\Support\Orm\Record;
use Ultra\UnitOfWork\Tests\Support\Orm\Registry;
use Ultra\UnitOfWork\TransactionalUnitOfWorkRunner;
use Ultra\UnitOfWork\UnitOfWork;
use Ultra\UnitOfWork\UnitOfWorkRunner;

final class OrmParticipationTest extends ContainerTestCase
{
    private Connection $connection;
    private EntityManager $manager;
    private Registry $registry;

    protected function setUp(): void
    {
        parent::setUp();
        $this->connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        $this->manager = $this->newManager();
        $this->registry = new Registry(['default' => $this->manager, 'application' => $this->newManager()]);
        new SchemaTool($this->manager)->createSchema([$this->manager->getClassMetadata(Record::class)]);
        $this->connection->executeStatement('CREATE TABLE ultra_records (value TEXT NOT NULL)');
    }

    protected function tearDown(): void
    {
        $this->connection->close();
        parent::tearDown();
    }

    public function test_container_participation_commits_orm_and_ultra_changes_and_detaches_entities(): void
    {
        $participant = $this->ultraParticipant();
        $container = $this
            ->ultraLight(withEventStore: false)
            ->withService($this->registry)
            ->withService(new DbalTransactionManager($this->connection))
            ->withAlias(\Doctrine\Persistence\ManagerRegistry::class, Registry::class)
            ->withAlias(\Ultra\UnitOfWork\TransactionManager::class, DbalTransactionManager::class)
            ->withClass(OrmUnitOfWork::class)
            ->withService($participant, 'test.ultra_participant')
            ->withAlias('test.orm', OrmUnitOfWork::class)
            ->boot();
        /** @var UnitOfWorkRunner $runner */
        $runner = $container->get('test.runner');
        $record = new Record('one', 'initial');
        $result = $runner->run(function () use ($record): string {
            static::assertFalse($this->connection->isTransactionActive());
            $this->manager->persist($record);
            static::assertSame(0, (int) $this->connection->fetchOne('SELECT count(*) FROM orm_records'));

            return 'result';
        });

        static::assertSame('result', $result);
        static::assertSame(['initial'], $this->connection->fetchFirstColumn('SELECT value FROM orm_records'));
        static::assertSame(['ultra'], $this->connection->fetchFirstColumn('SELECT value FROM ultra_records'));
        static::assertFalse($this->manager->contains($record));
        static::assertTrue($this->manager->isOpen());
        static::assertFalse($this->connection->isTransactionActive());
    }

    public function test_updates_to_loaded_entities_flush_at_the_outermost_run_without_explicit_scheduling(): void
    {
        $this->connection->insert('orm_records', ['id' => 'one', 'value' => 'initial']);
        $runner = $this->runner();
        $record = null;
        $runner->run(function () use ($runner, &$record): void {
            $record = $this->manager->find(Record::class, 'one');
            $runner->run(static function () use ($record): void {
                $record->value = 'changed';
            });
            static::assertTrue($this->manager->contains($record));
            static::assertSame('initial', $this->connection->fetchOne('SELECT value FROM orm_records'));
        });

        static::assertSame('changed', $this->connection->fetchOne('SELECT value FROM orm_records'));
        static::assertFalse($this->manager->contains($record));
    }

    #[DataProvider('failedStages')]
    public function test_abandoned_work_rolls_back_and_clears_without_closing_the_manager(string $stage): void
    {
        $failure = new \RuntimeException('failed ' . $stage);
        $participant = $this->ultraParticipant('work' === $stage ? null : $failure);
        $orm = new OrmUnitOfWork($this->registry);
        $participants = 'before orm' === $stage ? [$participant, $orm] : [$orm, $participant];
        $runner = new TransactionalUnitOfWorkRunner(
            new CompositeUnitOfWork($participants),
            new DbalTransactionManager($this->connection),
        );
        $record = new Record('one', 'abandoned');
        try {
            $runner->run(function () use ($stage, $failure, $record): void {
                $this->manager->persist($record);
                if ('work' === $stage) {
                    throw $failure;
                }
            });
            static::fail('The original exception must propagate.');
        } catch (\RuntimeException $exception) {
            static::assertSame($failure, $exception);
        }

        static::assertSame([], $this->connection->fetchFirstColumn('SELECT value FROM orm_records'));
        static::assertSame([], $this->connection->fetchFirstColumn('SELECT value FROM ultra_records'));
        static::assertFalse($this->manager->contains($record));
        static::assertTrue($this->manager->isOpen());
        static::assertFalse($this->connection->isTransactionActive());
        $this->runner()->run(function (): void {
            $this->manager->persist(new Record('two', 'fresh'));
        });
        static::assertSame(['fresh'], $this->connection->fetchFirstColumn('SELECT value FROM orm_records'));
    }

    /** @return iterable<string, array{string}> */
    public static function failedStages(): iterable
    {
        foreach (['work', 'before orm', 'after orm'] as $stage) {
            yield $stage => [$stage];
        }
    }

    public function test_an_orm_flush_failure_leaves_the_manager_closed_until_the_application_replaces_it(): void
    {
        $this->connection->insert('orm_records', ['id' => 'one', 'value' => 'existing']);
        $runner = $this->runner();
        try {
            $runner->run(function (): void {
                $this->manager->persist(new Record('one', 'duplicate'));
            });
            static::fail('The duplicate identifier must fail.');
        } catch (\Doctrine\DBAL\Exception\UniqueConstraintViolationException) {
            static::assertFalse($this->manager->isOpen());
        }
        static::assertFalse($this->connection->isTransactionActive());
        static::assertSame(['existing'], $this->connection->fetchFirstColumn('SELECT value FROM orm_records'));
        $replacement = $this->newManager();
        $this->registry->currentManagers['default'] = $replacement;
        $runner->run(static function () use ($replacement): void {
            $replacement->persist(new Record('two', 'recovered'));
        });
        static::assertSame(
            ['existing', 'recovered'],
            $this->connection->fetchFirstColumn('SELECT value FROM orm_records ORDER BY id'),
        );
    }

    #[DataProvider('managerNames')]
    public function test_registry_resolution_follows_the_selected_manager_between_runs(?string $managerName): void
    {
        $name = $managerName ?? 'default';
        if (null !== $managerName) {
            $this->registry->currentManagers[$name] = $this->newManager();
        }
        $runner = $this->runner($managerName);
        $first = $this->registry->getManager($name);
        $record = new Record('one', 'first');
        $runner->run(static function () use ($first, $record): void {
            $first->persist($record);
        });
        static::assertFalse($first->contains($record));
        static::assertTrue($first->isOpen());

        $replacement = $this->newManager();
        $this->registry->currentManagers[$name] = $replacement;
        $second = new Record('two', 'replacement');
        $runner->run(static function () use ($replacement, $second): void {
            $replacement->persist($second);
        });
        static::assertSame(
            ['first', 'replacement'],
            $this->connection->fetchFirstColumn('SELECT value FROM orm_records ORDER BY id'),
        );
        static::assertFalse($replacement->contains($second));
        static::assertTrue($replacement->isOpen());
        static::assertSame($replacement, $this->registry->getManager($name));
    }

    /** @return iterable<string, array{?string}> */
    public static function managerNames(): iterable
    {
        yield 'default manager' => [null];
        yield 'named manager' => ['application'];
    }

    private function newManager(): EntityManager
    {
        $configuration = new Configuration();
        $configuration->setMetadataDriverImpl(new AttributeDriver([__DIR__ . '/Support/Orm']));
        $configuration->enableNativeLazyObjects(true);

        return new EntityManager($this->connection, $configuration);
    }

    private function runner(?string $managerName = null): TransactionalUnitOfWorkRunner
    {
        $unitOfWork = new CompositeUnitOfWork([new OrmUnitOfWork($this->registry, $managerName)]);

        return new TransactionalUnitOfWorkRunner($unitOfWork, new DbalTransactionManager($this->connection));
    }

    private function ultraParticipant(?\Throwable $failure = null): UnitOfWork
    {
        return new class($this->connection, $failure) implements UnitOfWork {
            public function __construct(
                private Connection $connection,
                private ?\Throwable $failure,
            ) {}

            public function hasPendingChanges(): bool
            {
                return true;
            }

            public function flush(): void
            {
                \PHPUnit\Framework\Assert::assertTrue($this->connection->isTransactionActive());
                $this->connection->insert('ultra_records', ['value' => 'ultra']);
                if (null !== $this->failure) {
                    throw $this->failure;
                }
            }

            public function clear(): void {}
        };
    }
}
