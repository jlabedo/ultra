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
use Ultra\MappedTable\InMemoryMappedTableAdapter;
use Ultra\MappedTable\InMemoryRowDatabase;
use Ultra\MappedTable\MappedRowSet;
use Ultra\MappedTable\MappedTableAdapter;
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
use Ultra\Schema\Attribute\DefineSchema;
use Ultra\Schema\ColumnDefinition;
use Ultra\Schema\ColumnType;
use Ultra\Schema\ForeignKeyDefinition;
use Ultra\Schema\SchemaDefinition;
use Ultra\Schema\Symfony\SchemaExtension;
use Ultra\Schema\TableDefinition;
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
                extensionsBefore: [
                    new SchemaExtension(),
                    new MappedTableExtension(rowSets: ProjectionTablesSchema::rowSets()),
                ],
            )
            ->withExtension(new UnitOfWorkExtension())
            ->withService($connection, Connection::class)
            ->withService(new DbalTransactionManager($connection), TransactionManager::class)
            ->withService(new PostgresMappedTableAdapter($connection), MappedTableAdapter::class)
            ->withClass(CompiledMappedProjection::class, ProjectionTablesSchema::class)
            ->withAlias('test.projected_courses', ProjectionTablesSchema::COURSES)
            ->withAlias('test.application_rows', ProjectionTablesSchema::APPLICATION)
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
                ProjectionTablesSchema::class,
            ],
            new MappedTableExtension(rowSets: ProjectionTablesSchema::rowSets()),
            'must have a single owner',
        ];
        yield 'owner dependency split between coordinators' => [
            [ConflictingMappedProjection::class, ProjectionTablesSchema::class],
            new MappedTableExtension(rowSets: ProjectionTablesSchema::rowSets()),
            'must use the same coordinator',
        ];
        yield 'guard split between coordinators' => [
            [
                CompiledMappedProjection::class,
                ProjectionTablesSchema::class,
            ],
            new MappedTableExtension([
                new QueryGuardDefinition('split', [
                    new QueryGuardDependency(ProjectionTablesSchema::COURSES, 'id'),
                    new QueryGuardDependency(ProjectionTablesSchema::APPLICATION, 'id'),
                ]),
            ], rowSets: ProjectionTablesSchema::rowSets()),
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
            ->withExtension(new SchemaExtension())
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
            ->withExtension(new SchemaExtension())
            ->withExtension(new MappedTableExtension(rowSets: [ProjectionTablesSchema::COURSES]))
            ->withService(new InMemoryMappedTableAdapter(new InMemoryRowDatabase()), MappedTableAdapter::class)
            ->withClass(UndeclaredMappedProjection::class, ProjectionTablesSchema::class)
            ->withAlias('test.course_table_alias', ProjectionTablesSchema::COURSES)
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
        new SchemaExtension()->build($container);
        new MappedTableExtension(rowSets: [ProjectionTablesSchema::COURSES])->build($container);
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
        $container->register(ProjectionTablesSchema::class)->setAutoconfigured(true);
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
    rowSets: [ProjectionTablesSchema::DETAILS, ProjectionTablesSchema::COURSES],
)]
final readonly class CompiledMappedProjection
{
    public function __construct(
        #[Autowire(service: ProjectionTablesSchema::COURSES)]
        private MappedRowSet $courses,
        #[Autowire(service: ProjectionTablesSchema::DETAILS)]
        private MappedRowSet $details,
        private Connection $connection,
        #[DefineQueryGuard('compiled_projection_courses', [new QueryGuardDependency(
            ProjectionTablesSchema::COURSES,
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
    rowSets: [ProjectionTablesSchema::COURSES],
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

final class ProjectionTablesSchema
{
    public const string COURSES = 'mapped_projection_courses';
    public const string DETAILS = 'mapped_projection_details';
    public const string APPLICATION = 'mapped_projection_application';

    #[DefineSchema]
    public static function definition(): SchemaDefinition
    {
        return new SchemaDefinition([
            new TableDefinition(
                self::COURSES,
                columns: [
                    new ColumnDefinition('id', type: ColumnType::Text),
                    new ColumnDefinition('name', type: ColumnType::Text),
                ],
                primaryKey: ['id'],
            ),
            new TableDefinition(
                self::DETAILS,
                columns: [
                    new ColumnDefinition('course_id', type: ColumnType::Text),
                    new ColumnDefinition('id', type: ColumnType::Text),
                ],
                primaryKey: ['course_id', 'id'],
                foreignKeys: ['owner' => new ForeignKeyDefinition(self::COURSES, ['course_id' => 'id'])],
            ),
            new TableDefinition(
                self::APPLICATION,
                columns: [
                    new ColumnDefinition('id', type: ColumnType::Text),
                ],
                primaryKey: ['id'],
            ),
        ]);
    }

    /** @return array<array-key, string|array{owner: string}> */
    public static function rowSets(): array
    {
        return [
            self::COURSES,
            self::DETAILS => ['owner' => 'owner'],
            self::APPLICATION,
        ];
    }
}
