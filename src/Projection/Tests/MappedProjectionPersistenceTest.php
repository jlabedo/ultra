<?php

declare(strict_types=1);

namespace Ultra\Projection\Tests;

use Doctrine\DBAL\Connection;
use PHPUnit\Framework\Attributes\CoversNamespace;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Reference;
use Ultra\EventStore\EventStore;
use Ultra\EventStore\EventStoreUnitOfWork;
use Ultra\EventStore\StreamQuery;
use Ultra\EventStore_Postgres\PostgresEventStoreHStore;
use Ultra\EventStore_Postgres\PostgresEventStoreQueryStreams;
use Ultra\MappedTable\Attribute\DefineQueryGuard;
use Ultra\MappedTable\GuardLockAdapter;
use Ultra\MappedTable\InMemoryMappedTableAdapter;
use Ultra\MappedTable\InMemoryRowDatabase;
use Ultra\MappedTable\MappedColumn;
use Ultra\MappedTable\MappedRowSet;
use Ultra\MappedTable\MappedRowSetDefinition;
use Ultra\MappedTable\MappedRowSetMapping;
use Ultra\MappedTable\MappedRowSetOwner;
use Ultra\MappedTable\MappedTableAdapter;
use Ultra\MappedTable\PostgresGuardLockAdapter;
use Ultra\MappedTable\PostgresMappedTableAdapter;
use Ultra\MappedTable\QueryGuard;
use Ultra\MappedTable\QueryGuardDefinition;
use Ultra\MappedTable\QueryGuardDependency;
use Ultra\MappedTable\Symfony\MappedTableExtension;
use Ultra\Mapper\UltraNormalizer;
use Ultra\Projection\Attribute\Projection;
use Ultra\Projection\Attribute\ProjectionHandler;
use Ultra\Projection\Attribute\ProjectionResetter;
use Ultra\Projection\Bundle\ProjectionExtension;
use Ultra\Projection\Bundle\ProjectionImplementationInterface;
use Ultra\Projection\ProjectionMode;
use Ultra\Projection\ProjectionRebuilder;
use Ultra\Projection\Tests\Support\ProjectionTestCase;
use Ultra\Projection\Tests\Support\RunnerCourseCreated;
use Ultra\Projection_Postgres\PostgresProjectionImplementation;
use Ultra\UnitOfWork\DbalTransactionManager;
use Ultra\UnitOfWork\TransactionManager;
use Ultra\UnitOfWork\UnitOfWorkExtension;
use Ultra\UnitOfWork\UnitOfWorkRunner;

/** @internal */
#[CoversNamespace('Ultra\\Projection')]
#[CoversNamespace('Ultra\\Projection_Postgres')]
#[CoversNamespace('Ultra\\MappedTable')]
final class MappedProjectionPersistenceTest extends ProjectionTestCase
{
    public function test_compiled_private_tables_follow_the_projection_lifecycle(): void
    {
        $fixture = $this->fixture('postgres');
        $connection = $fixture->connection;
        assert(null !== $connection);
        $fixture->registerReadModel('mapped_projection_details');
        $fixture->registerReadModel('mapped_projection_courses');
        $fixture->registerReadModel('mapped_projection_application');
        $connection->executeStatement(
            'CREATE TEMP TABLE mapped_projection_courses (id TEXT PRIMARY KEY, name TEXT NOT NULL)',
        );
        $connection->executeStatement(<<<'SQL'
            CREATE TEMP TABLE mapped_projection_details (
                course_id TEXT REFERENCES mapped_projection_courses (id), id TEXT, PRIMARY KEY (course_id, id)
            )
            SQL);
        $connection->executeStatement('CREATE TEMP TABLE mapped_projection_application (id TEXT PRIMARY KEY)');
        $names = static fn(): array => $connection->fetchFirstColumn(
            'SELECT name FROM mapped_projection_courses ORDER BY id',
        );
        $container = $this
            ->projectionUltraLight(
                $fixture->store,
                new PostgresProjectionImplementation($connection, $fixture->prefix),
                extensionsBefore: [new MappedTableExtension()],
            )
            ->withExtension(new UnitOfWorkExtension())
            ->withService($connection, Connection::class)
            ->withService(new DbalTransactionManager($connection), TransactionManager::class)
            ->withService(new PostgresMappedTableAdapter($connection), MappedTableAdapter::class)
            ->withService(new PostgresGuardLockAdapter($connection), GuardLockAdapter::class)
            ->withClass(
                CompiledMappedProjection::class,
                ProjectedCoursesDefinition::class,
                ProjectedDetailsDefinition::class,
                ApplicationRowsDefinition::class,
            )
            ->withAlias('test.projected_courses', ProjectedCoursesDefinition::class)
            ->withAlias('test.application_rows', ApplicationRowsDefinition::class)
            ->withAlias('test.rebuilder', ProjectionRebuilder::class)
            ->withAlias('test.runner', UnitOfWorkRunner::class)
            ->withAlias('test.direct_store', EventStoreUnitOfWork::class . '.inner')
            ->boot();
        $projectionRows = $container->get('test.projected_courses');
        $applicationRows = $container->get('test.application_rows');
        $runner = $container->get('test.runner');
        $rebuilder = $container->get('test.rebuilder');
        $runtime = $container->projectionCatalog()->runtime('compiled_mapped_projection');
        $store = $container->eventStore();

        $projectionRows->withKey('probe')->insert()->setColumn('name', 'unflushed');
        $runner->run(static fn() => $applicationRows->withKey('app')->insert());
        static::assertSame(['app'], $connection->fetchFirstColumn('SELECT id FROM mapped_projection_application'));
        static::assertSame([], $names());
        static::assertTrue($projectionRows->hasPendingChanges());
        $projectionRows->clear();

        $runner->run(static fn() => $store->append([self::courseCreatedEvent('a', 'history')]));
        static::assertSame([], $names());
        $rebuilder->activate($runtime);
        static::assertSame(['history'], $names());

        $runner->run(static fn() => $store->append([self::courseCreatedEvent('b', 'buffered')]));
        $directStore = $container->get('test.direct_store');
        $directStore->append([self::courseCreatedEvent('c', 'direct')]);
        static::assertSame(['history', 'buffered', 'direct'], $names());

        try {
            $directStore->append([self::courseCreatedEvent('d', 'fail')]);
            static::fail('Expected projection failure.');
        } catch (\RuntimeException $e) {
            static::assertSame('Projection handler failed.', $e->getMessage());
        }
        static::assertFalse($projectionRows->hasPendingChanges());
        static::assertCount(3, $fixture->store->load(StreamQuery::all())->events);
        static::assertSame(['history', 'buffered', 'direct'], $names());

        $directStore->append([self::courseCreatedEvent('d', 'retry')]);
        $container->projectionRunner()->deleteProjection($runtime);
        static::assertSame([], $names());
        static::assertSame(0, (int) $connection->fetchOne('SELECT count(*) FROM mapped_projection_details'));
        $rebuilder->activate($runtime);
        static::assertSame(['history', 'buffered', 'direct', 'retry'], $names());
        static::assertSame(4, (int) $connection->fetchOne('SELECT count(*) FROM mapped_projection_details'));
        static::assertFalse($projectionRows->hasPendingChanges());
    }

    /** @return iterable<string, array{list<class-string>, ?MappedTableExtension, string}> */
    public static function invalidOwnership(): iterable
    {
        yield 'missing extension' => [
            [CompiledMappedProjection::class],
            null,
            'require MappedTableExtension',
        ];
        yield 'missing definition' => [
            [CompiledMappedProjection::class],
            new MappedTableExtension(),
            'requires a registered mapped row set definition',
        ];
        yield 'two projections claiming one row set' => [
            [
                CompiledMappedProjection::class,
                ConflictingMappedProjection::class,
                ProjectedCoursesDefinition::class,
                ProjectedDetailsDefinition::class,
            ],
            new MappedTableExtension(),
            'must have a single owner',
        ];
        yield 'owner dependency split between coordinators' => [
            [ConflictingMappedProjection::class, ProjectedCoursesDefinition::class, ProjectedDetailsDefinition::class],
            new MappedTableExtension(),
            'must use the same coordinator',
        ];
        yield 'guard split between coordinators' => [
            [
                CompiledMappedProjection::class,
                ProjectedCoursesDefinition::class,
                ProjectedDetailsDefinition::class,
                ApplicationRowsDefinition::class,
            ],
            new MappedTableExtension([
                new QueryGuardDefinition('split', [
                    new QueryGuardDependency(ProjectedCoursesDefinition::class, 'id'),
                    new QueryGuardDependency(ApplicationRowsDefinition::class, 'id'),
                ]),
            ]),
            'must watch row sets in the same coordinator',
        ];
    }

    /**
     * @param list<class-string> $classes
     */
    #[DataProvider('invalidOwnership')]
    public function test_invalid_mapped_table_ownership_is_rejected_during_compilation(
        array $classes,
        ?MappedTableExtension $mappedTable,
        string $message,
    ): void {
        $builder = $this
            ->projectionUltraLight()
            ->withClass(...$classes)
            ->withService(new InMemoryMappedTableAdapter(new InMemoryRowDatabase()), MappedTableAdapter::class);
        if (null !== $mappedTable) {
            $builder = $builder->withExtension($mappedTable);
        }
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage($message);
        $builder->boot();
    }

    public function test_direct_injection_requires_the_projection_to_declare_the_row_set(): void
    {
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('and must declare it in rowSets');
        $this
            ->projectionUltraLight()
            ->withExtension(new MappedTableExtension())
            ->withService(new InMemoryMappedTableAdapter(new InMemoryRowDatabase()), MappedTableAdapter::class)
            ->withClass(UndeclaredMappedProjection::class, ProjectedCoursesDefinition::class)
            ->withAlias('test.course_table_alias', ProjectedCoursesDefinition::class)
            ->boot();
    }

    /** @return iterable<string, array{class-string<EventStore>, ?string, bool}> */
    public static function postgresConnectionConfigurations(): iterable
    {
        yield 'shared connection via alias' => [PostgresEventStoreQueryStreams::class, null, true];
        yield 'QueryStreams store on a separate connection' => [
            PostgresEventStoreQueryStreams::class,
            EventStore::class,
            true,
        ];
        yield 'HStore store on a separate connection' => [PostgresEventStoreHStore::class, EventStore::class, true];
        yield 'private tables on a separate connection' => [
            PostgresEventStoreQueryStreams::class,
            MappedTableAdapter::class,
            true,
        ];
        yield 'autowired connection service is not shared' => [PostgresEventStoreQueryStreams::class, null, false];
    }

    /** @param class-string<EventStore> $eventStore */
    #[DataProvider('postgresConnectionConfigurations')]
    public function test_standard_postgres_persistence_uses_one_connection_service(
        string $eventStore,
        ?string $separate,
        bool $shared,
    ): void {
        $container = new ContainerBuilder();
        new ProjectionExtension()->build($container);
        new MappedTableExtension()->build($container);
        new UnitOfWorkExtension()->build($container);
        $container->register(UltraNormalizer::class);
        $container->register('test.projection_connection', Connection::class)->setSynthetic(true)->setPublic(true);
        $container->register('test.different_connection', Connection::class)->setSynthetic(true)->setPublic(true);
        $container->getDefinition('test.projection_connection')->setShared($shared);
        $container->setAlias(Connection::class, 'test.projection_connection');
        $container->setAlias('test.shared_connection_alias', 'test.projection_connection');
        foreach ([
            EventStore::class => $eventStore,
            ProjectionImplementationInterface::class => PostgresProjectionImplementation::class,
            MappedTableAdapter::class => PostgresMappedTableAdapter::class,
        ] as $id => $class) {
            $definition = $container->register($id, $class)->setAutowired(true);
            if ($id === $separate) {
                $definition->setArgument('$connection', new Reference('test.different_connection'));
            } elseif (EventStore::class === $id) {
                $definition->setArgument('$connection', new Reference('test.shared_connection_alias'));
            }
        }
        $container->getDefinition(EventStore::class)->setPublic(true);
        $container->register(ProjectedCoursesDefinition::class)->setAutoconfigured(true);
        $container->register(ConflictingMappedProjection::class)->setAutoconfigured(true);

        if (null !== $separate || !$shared) {
            $this->expectException(\LogicException::class);
            $this->expectExceptionMessage(
                $shared ? 'requires the same connection' : 'needs a shared connection service',
            );
        }
        $container->compile();
        static::assertTrue($container->isCompiled());
    }
}

#[Projection(
    name: 'compiled_mapped_projection',
    partitionBy: 'courseId',
    mode: ProjectionMode::Synchronous,
    rowSets: [ProjectedDetailsDefinition::class, ProjectedCoursesDefinition::class],
)]
final readonly class CompiledMappedProjection
{
    public function __construct(
        #[Autowire(service: ProjectedCoursesDefinition::class)]
        private MappedRowSet $courses,
        #[Autowire(service: ProjectedDetailsDefinition::class)]
        private MappedRowSet $details,
        private Connection $connection,
        #[DefineQueryGuard('compiled_projection_courses', [new QueryGuardDependency(
            ProjectedCoursesDefinition::class,
            'id',
        )])]
        private QueryGuard $guard,
    ) {}

    #[ProjectionHandler]
    public function created(RunnerCourseCreated $event): void
    {
        $this->guard->observe(
            $event->courseId,
            fn(): int => (int) $this->connection->fetchOne('SELECT count(*) FROM mapped_projection_courses WHERE id = ?', [
                $event->courseId,
            ]),
        );
        $this->details->withKey(['course_id' => $event->courseId, 'id' => 'detail'])->insert();
        $this->courses->withKey($event->courseId)->insert()->setColumn('name', $event->name);
        if ('fail' === $event->name) {
            throw new \RuntimeException('Projection handler failed.');
        }
    }

    #[ProjectionResetter]
    public function reset(): void
    {
        foreach ($this->connection->fetchFirstColumn('SELECT id FROM mapped_projection_courses') as $courseId) {
            assert(is_string($courseId), 'The course ID is a PostgreSQL TEXT key.');
            $this->details->reconcileForOwner(['course_id' => $courseId], []);
            $this->courses->withKey($courseId)->delete();
        }
    }
}

#[Projection(
    name: 'conflicting_mapped_projection',
    partitionBy: 'courseId',
    rowSets: [ProjectedCoursesDefinition::class],
)]
final class ConflictingMappedProjection {}

#[Projection(name: 'undeclared_mapped_projection', partitionBy: 'courseId')]
final readonly class UndeclaredMappedProjection
{
    public function __construct(
        #[Autowire(service: 'test.course_table_alias')]
        public MappedRowSet $courses,
    ) {}
}

final class ProjectedCoursesDefinition implements MappedRowSetDefinition
{
    public function mapping(): MappedRowSetMapping
    {
        return new MappedRowSetMapping(
            'mapped_projection_courses',
            [
                'id' => new MappedColumn('string'),
                'name' => new MappedColumn('string'),
            ],
            ['id'],
        );
    }
}

final class ProjectedDetailsDefinition implements MappedRowSetDefinition
{
    public function mapping(): MappedRowSetMapping
    {
        return new MappedRowSetMapping(
            'mapped_projection_details',
            [
                'course_id' => new MappedColumn('string'),
                'id' => new MappedColumn('string'),
            ],
            ['course_id', 'id'],
            owner: new MappedRowSetOwner(ProjectedCoursesDefinition::class, ['course_id' => 'id']),
        );
    }
}

final class ApplicationRowsDefinition implements MappedRowSetDefinition
{
    public function mapping(): MappedRowSetMapping
    {
        return new MappedRowSetMapping('mapped_projection_application', ['id' => new MappedColumn('string')], ['id']);
    }
}
