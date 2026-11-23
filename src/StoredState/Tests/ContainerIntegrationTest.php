<?php

declare(strict_types=1);

namespace Ultra\StateStore\Tests;

use Doctrine\DBAL\Connection;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\DependencyInjection\ChildDefinition;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Definition;
use Symfony\Component\DependencyInjection\Dumper\PhpDumper;
use Symfony\Component\DependencyInjection\Reference;
use Ultra\EventStore\EventStore;
use Ultra\MappedTable\Attribute\DefineQueryGuard;
use Ultra\MappedTable\Exception\PendingQueryChangesException;
use Ultra\MappedTable\GuardLockAdapter;
use Ultra\MappedTable\InMemoryGuardLockAdapter;
use Ultra\MappedTable\InMemoryMappedTableAdapter;
use Ultra\MappedTable\InMemoryRowDatabase;
use Ultra\MappedTable\MappedRowSet;
use Ultra\MappedTable\MappedRowSetDefinition;
use Ultra\MappedTable\MappedRowSetMapping;
use Ultra\MappedTable\MappedTableAdapter;
use Ultra\MappedTable\PostgresGuardLockAdapter;
use Ultra\MappedTable\PostgresMappedTableAdapter;
use Ultra\MappedTable\QueryGuard;
use Ultra\MappedTable\QueryGuardDependency;
use Ultra\MappedTable\Symfony\MappedTableExtension;
use Ultra\Mapper_Bundle\MapperExtension;
use Ultra\Mapper_Bundle\TagNames as MapperTagNames;
use Ultra\StateStore\Attribute\State;
use Ultra\StateStore\Attribute\StateCollection;
use Ultra\StateStore\Attribute\StateColumn;
use Ultra\StateStore\Attribute\StateEmbedded;
use Ultra\StateStore\Attribute\StateId;
use Ultra\StateStore\Exception\InvalidStateConfigurationException;
use Ultra\StateStore\StateStore;
use Ultra\StateStore\Tests\Support\NullEventStore;
use Ultra\StateStore\Tests\Support\OrderLinesDefinition;
use Ultra\StateStore\Tests\Support\OrdersDefinition;
use Ultra\StateStore\Tests\Support\StateStoreTestCase;
use Ultra\StateStore_Bundle\DependencyInjection\Compiler\StateStoreCompilerPass;
use Ultra\StateStore_Bundle\StateStoreExtension;
use Ultra\StateStore_Bundle\TagNames;
use Ultra\UnitOfWork\Exception\ConcurrencyException;
use Ultra\UnitOfWork\UnitOfWork;
use Ultra\UnitOfWork\UnitOfWorkExtension;

#[State(table: 'orders')]
final readonly class BundleOrderState
{
    public function __construct(
        #[StateId(column: 'order_id')]
        public string $orderId,
        #[StateColumn('status')]
        public string $status,
        #[StateColumn('note')]
        public ?string $note,
    ) {}

    public function withStatus(string $status): self
    {
        return new self($this->orderId, $status, $this->note);
    }
}

#[State(table: 'orders')]
final readonly class BundleOrderStatusView
{
    public function __construct(
        #[StateId(column: 'order_id')]
        public string $orderId,
        #[StateColumn('status')]
        public string $status,
    ) {}

    public function withStatus(string $status): self
    {
        return new self($this->orderId, $status);
    }
}

/** @internal */
final class ContainerIntegrationTest extends StateStoreTestCase
{
    public function test_query_guards_are_compiled_injected_and_work_in_a_dumped_container(): void
    {
        $builder = $this->createContainer(withQueryGuard: true);
        $class = 'QueryGuardTestContainer_' . bin2hex(random_bytes(4));
        $php = new PhpDumper($builder)->dump(['class' => $class]);
        eval(substr($php, 5));
        $container = new $class();
        $database = $container->get(InMemoryRowDatabase::class);
        $store = $container->get('test.state_store');
        $unitOfWork = $container->get(UnitOfWork::class);
        $repository = $container->get(BundleGuardedRepository::class);
        $store->observe(BundleOrderState::class, 'one');
        $store->save(new BundleOrderState('one', 'pending', 'draft'));
        $database->beginTransaction();
        $unitOfWork->flush();
        $database->commit();
        $unitOfWork->clear();

        static::assertSame('pending', $repository->status('one'));
        $database->seedRow('orders', 'order_id', ['order_id' => 'one', 'status' => 'shipped', 'note' => 'draft']);
        $database->beginTransaction();
        try {
            $this->expectException(ConcurrencyException::class);
            $unitOfWork->flush();
        } finally {
            $database->rollBack();
            $unitOfWork->clear();
        }
    }

    public function test_guard_definitions_affect_writers_even_if_the_repository_is_never_called(): void
    {
        $container = $this->createContainer(withQueryGuard: true);
        $store = $container->get('test.state_store');
        // The identifier scope is known even for a blind upsert; its writer guard needs a transaction.
        $store->save(new BundleOrderState('one', 'pending', 'draft'));
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('active transaction');
        $container->get(UnitOfWork::class)->flush();
    }

    public function test_query_guards_require_a_dedicated_adapter_during_compilation(): void
    {
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('GuardLockAdapter');
        $this->createContainer(withQueryGuard: true, withGuardAdapter: false);
    }

    public function test_state_resources_reject_non_string_class_names_during_compilation(): void
    {
        $container = new ContainerBuilder();
        $container->setDefinition('invalid.state', new Definition(\stdClass::class)->addResourceTag(TagNames::STATE, [
            'class' => 42,
        ]));
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('has no class');

        new StateStoreCompilerPass()->process($container);
    }

    public function test_unknown_guard_columns_are_rejected_during_compilation(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Unknown column "missing"');
        $this->createContainer(extraGuardRepository: BundleInvalidGuardRepository::class);
    }

    public function test_conflicting_definitions_for_the_same_guard_are_rejected_during_compilation(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('unique, non-empty name');
        $this->createContainer(withQueryGuard: true, extraGuardRepository: BundleConflictingGuardRepository::class);
    }

    public function test_guard_attributes_require_a_mapped_query_guard_parameter(): void
    {
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('must be typed as ' . QueryGuard::class);
        $this->createContainer(extraGuardRepository: BundleWrongGuardParameter::class);
    }

    public function test_bundle_registers_state_store_and_shared_mapped_row_set_services(): void
    {
        $container = $this->createContainer(withGuardAdapter: false);
        $database = $container->get(InMemoryRowDatabase::class);
        $store = $container->get('test.state_store');
        $unitOfWork = $container->get(UnitOfWork::class);

        static::assertInstanceOf(StateStore::class, $store);

        $orders = $container->get('test.orders');
        $lines = $container->get('test.lines');
        static::assertInstanceOf(MappedRowSet::class, $orders);
        static::assertInstanceOf(MappedRowSet::class, $lines);

        $store->save(new BundleOrderState('order-123', 'pending', 'draft'));
        static::assertSame(['status' => 'pending'], $orders->withKey('order-123')->get(['status']));
        $this->flush($database, $unitOfWork);

        static::assertSame(
            [
                'status' => 'pending',
                'note' => 'draft',
            ],
            $database->row('orders', 'order_id', 'order-123', ['status', 'note']),
        );

        $view = $store->observe(BundleOrderStatusView::class, 'order-123');
        static::assertInstanceOf(BundleOrderStatusView::class, $view);

        $orders->withKey('order-123')->setColumn('status', 'shipped');
        static::assertEquals(
            new BundleOrderStatusView('order-123', 'shipped'),
            $store->load(BundleOrderStatusView::class, 'order-123'),
        );
        $this->flush($database, $unitOfWork);

        static::assertSame(
            [
                'status' => 'shipped',
                'note' => 'draft',
            ],
            $database->row('orders', 'order_id', 'order-123', ['status', 'note']),
        );
        $unitOfWork->clear();
        $state = new OrderWithLinesState('order-123', 'paid', [new OrderLineState('one', 'SKU', 2)]);
        $store->save($state);
        $key = ['order_id' => 'order-123', 'line_id' => 'one'];
        static::assertSame(['quantity' => 2], $lines->withKey($key)->get(['quantity']));
        $lines->withKey($key)->setColumn('quantity', 3);
        $state = new OrderWithLinesState('order-123', 'paid', [new OrderLineState('one', 'SKU', 3)]);
        static::assertEquals($state, $store->load(OrderWithLinesState::class, 'order-123'));
        $this->flush($database, $unitOfWork);
        $unitOfWork->clear();
        static::assertEquals($state, $store->load(OrderWithLinesState::class, 'order-123'));
        static::assertSame(
            ['quantity' => 3],
            $database->row('order_lines', 'line_id', 'one', ['quantity'], ['order_id' => 'order-123']),
        );
        $this->expectException(\Ultra\StateStore\Exception\UnknownStateException::class);
        $store->load(OrderLineState::class, 'one');
    }

    public function test_direct_writes_participate_in_state_query_guards(): void
    {
        $container = $this->createContainer(withQueryGuard: true);
        $database = $container->get(InMemoryRowDatabase::class);
        $database->seedRow('orders', 'order_id', ['order_id' => 'one', 'status' => 'pending']);
        $container->get('test.orders')->withKey('one')->setColumn('status', 'shipped');

        $this->expectException(PendingQueryChangesException::class);
        $container->get(BundleGuardedRepository::class)->status('one');
    }

    public function test_empty_reconciliation_participates_in_state_collection_guards(): void
    {
        $container = $this->createContainer(extraGuardRepository: BundleLinesGuardRepository::class);
        $container->get('test.lines')->reconcileForOwner(['order_id' => 'one'], []);

        $this->expectException(PendingQueryChangesException::class);
        $this->expectExceptionMessage(OrderLinesDefinition::class);
        $container->get(BundleLinesGuardRepository::class)->observe('one');
    }

    public function test_manual_sql_expectations_run_before_shared_writes(): void
    {
        $container = $this->createContainer(withGuardAdapter: false);
        $database = $container->get(InMemoryRowDatabase::class);
        $store = $container->get('test.state_store');
        $orders = $container->get('test.orders');
        $unitOfWork = $container->get(UnitOfWork::class);
        $query = static fn(): array|false => $database->row('orders', 'order_id', 'one', ['status']);

        static::assertFalse($store->observeQuery($query, 'prior order'));
        $orders->withKey('one')->insert()->setColumn('status', 'created directly');
        $this->flush($database, $unitOfWork);
        static::assertSame(['status' => 'created directly'], $query());
        static::assertFalse($unitOfWork->hasPendingChanges());
    }

    public function test_state_observation_uses_mapper_representations_and_its_optimistic_projection(): void
    {
        $container = $this->createContainer(extraStates: [BundleOrderEnumView::class]);
        $database = $container->get(InMemoryRowDatabase::class);
        $database->seedRow('orders', 'order_id', ['order_id' => 'order', 'status' => 'pending', 'note' => 'original']);
        $store = $container->get('test.state_store');
        $orders = $container->get('test.orders');
        $unitOfWork = $container->get(UnitOfWork::class);

        $orders->withKey('order')->setColumn('status', 'shipped');
        static::assertEquals(
            new BundleOrderEnumView('order', BundleStatus::Shipped, 'original'),
            $store->observe(BundleOrderEnumView::class, 'order'),
        );
        static::assertSame([], $orders->updatePlan()->rowUpdates[0]->expectedColumns);
        $database->seedRow('orders', 'order_id', ['order_id' => 'order', 'status' => 'pending', 'note' => 'different']);
        $this->flush($database, $unitOfWork);

        static::assertEquals(
            new BundleOrderEnumView('order', BundleStatus::Shipped, 'different'),
            $store->load(BundleOrderEnumView::class, 'order'),
        );
    }

    public function test_postgres_orders_owner_writes_and_shares_collection_expectations(): void
    {
        $fixture = $this->stateFixture('postgres', [OrderWithLinesState::class]);
        self::assertInstanceOf(Connection::class, $fixture->storage);
        $database = $fixture->storage;
        $container = $this->createContainer(withGuardAdapter: false, postgres: $database);
        $store = $container->get('test.state_store');
        $lines = $container->get('test.lines');
        $unitOfWork = $container->get(UnitOfWork::class);

        $state = new OrderWithLinesState('order', 'open', [new OrderLineState('line', 'SKU', 1)]);
        $store->save($state);
        static::assertSame(
            ['quantity' => 1],
            $lines->withKey(['order_id' => 'order', 'line_id' => 'line'])->get(['quantity']),
        );
        $database->transactional(static fn() => $unitOfWork->flush());
        static::assertFalse($unitOfWork->hasPendingChanges());
        static::assertEquals($state, $store->observe(OrderWithLinesState::class, 'order'));

        // Observing a state collection protects the membership while permitting item value changes.
        $database->executeStatement("UPDATE order_lines SET quantity = 2 WHERE line_id = 'line'");
        $database->transactional(static fn() => $unitOfWork->flush());
        $store->observe(OrderWithLinesState::class, 'order');
        $database->executeStatement("INSERT INTO order_lines VALUES ('order', 'other', 'SKU', 3)");
        $store->delete(OrderWithLinesState::class, 'order');

        try {
            $database->transactional(static fn() => $unitOfWork->flush());
            static::fail('Concurrent membership changes must reject owner deletion.');
        } catch (ConcurrencyException) {
            $unitOfWork->clear();
        }

        static::assertSame(2, (int) $database->fetchOne('SELECT COUNT(*) FROM order_lines'));
        $store->delete(OrderWithLinesState::class, 'order');
        static::assertSame([], $lines->rowsForOwner(['order_id' => 'order']));
        $database->transactional(static fn() => $unitOfWork->flush());
        static::assertSame(0, (int) $database->fetchOne('SELECT COUNT(*) FROM orders'));
        static::assertSame(0, (int) $database->fetchOne('SELECT COUNT(*) FROM order_lines'));
    }

    /** @return iterable<string, array{class-string, string}> */
    public static function invalidBindings(): iterable
    {
        yield 'missing definition' => [BundleUnmappedState::class, 'needs a mapped row set definition'];
        yield 'incomplete key' => [BundleWrongKeyState::class, 'must map the complete key'];
        yield 'alternate key for the same table' => [BundleAlternateOrderKey::class, 'must map the complete key'];
        yield 'key representation' => [BundleWrongKeyTypeState::class, 'property "id" normalizes to int'];
        yield 'embedded column' => [
            BundleUnknownEmbeddedColumnState::class,
            'maps unknown column "orders.missing_value"',
        ];
        yield 'nullability' => [BundleWrongNullabilityState::class, 'mapped column "orders.note" requires string|null'];
        yield 'collection item type for hydration' => [
            BundleWrongCollectionType::class,
            'must declare a collection of',
        ];
    }

    #[DataProvider('invalidBindings')]
    public function test_incompatible_state_mappings_are_rejected_during_compilation(
        string $class,
        string $message,
    ): void {
        $this->expectException(InvalidStateConfigurationException::class);
        $this->expectExceptionMessage($message);
        $this->createContainer(extraStates: [$class]);
    }

    public function test_collections_require_the_matching_owner_dependency_during_compilation(): void
    {
        $this->expectException(InvalidStateConfigurationException::class);
        $this->expectExceptionMessage('must match the owner dependency');
        $this->createContainer(rowSets: [OrdersDefinition::class, BundleLinesWithoutDependency::class]);
    }

    public function test_states_cannot_bind_projection_owned_tables(): void
    {
        $this->expectException(InvalidStateConfigurationException::class);
        $this->expectExceptionMessage('StateStore cannot bind table');
        $this->createContainer(privateRowSets: [OrdersDefinition::class, OrderLinesDefinition::class]);
    }

    public function test_bundle_autoconfiguration_marks_states_as_normalizable_resources(): void
    {
        $container = new ContainerBuilder();
        new StateStoreExtension()->build($container);

        $definition = new ChildDefinition('');
        $configurator = $container->getAttributeAutoconfigurators()[State::class][0];
        $configurator($definition, new State('orders'), new \ReflectionClass(BundleOrderState::class));

        static::assertSame(
            [[
                'class' => BundleOrderState::class,
            ]],
            $definition->getTag(TagNames::STATE),
        );
        static::assertSame(
            [[
                'class' => BundleOrderState::class,
            ]],
            $definition->getTag(MapperTagNames::NORMALIZABLE),
        );
    }

    /**
     * @param list<class-string<MappedRowSetDefinition>> $rowSets
     * @param list<class-string> $extraStates
     * @param list<class-string<MappedRowSetDefinition>> $privateRowSets
     */
    private function createContainer(
        bool $withQueryGuard = false,
        ?string $extraGuardRepository = null,
        bool $withGuardAdapter = true,
        array $rowSets = [OrdersDefinition::class, OrderLinesDefinition::class],
        array $extraStates = [],
        ?Connection $postgres = null,
        array $privateRowSets = [],
    ): ContainerBuilder {
        $container = new ContainerBuilder();
        $container->setParameter('kernel.build_dir', sys_get_temp_dir() . '/ultra_state_store_bundle_test');
        new MapperExtension()->build($container);
        new UnitOfWorkExtension()->build($container);
        new StateStoreExtension()->build($container);
        new MappedTableExtension()->build($container);
        if ([] !== $privateRowSets) {
            MappedTableExtension::privateUnitOfWork($container, 'test.projection', $privateRowSets);
        }
        foreach ($rowSets as $definition) {
            $container->register($definition)->setAutoconfigured(true);
        }
        $container->setAlias('test.orders', OrdersDefinition::class)->setPublic(true);
        $container->setAlias('test.lines', OrderLinesDefinition::class)->setPublic(true);

        $container->register(NullEventStore::class, NullEventStore::class)->setPublic(true);
        $container->setAlias(EventStore::class, NullEventStore::class)->setPublic(true);

        if (null === $postgres) {
            $storage = InMemoryRowDatabase::class;
            $factory = InMemoryMappedTableAdapter::class;
            $guard = InMemoryGuardLockAdapter::class;
            $container->register($storage, $storage)->setPublic(true);
        } else {
            $storage = Connection::class;
            $factory = PostgresMappedTableAdapter::class;
            $guard = PostgresGuardLockAdapter::class;
            $container->register($storage, $storage)->setPublic(true)->setSynthetic(true);
        }
        $container->register(MappedTableAdapter::class, $factory)->setArguments([new Reference($storage)]);
        if ($withGuardAdapter) {
            $container->register(GuardLockAdapter::class, $guard)->setArguments([new Reference($storage)]);
        }
        $container->setAlias('test.state_store', StateStore::class)->setPublic(true);

        $container->setDefinition(
            BundleOrderState::class,
            new Definition(BundleOrderState::class)->setAutoconfigured(true),
        );
        $container->setDefinition(
            BundleOrderStatusView::class,
            new Definition(BundleOrderStatusView::class)->setAutoconfigured(true),
        );

        foreach ([OrderWithLinesState::class, ...$extraStates] as $class) {
            $container->setDefinition($class, new Definition($class)->setAutoconfigured(true));
        }

        $container->getAlias(UnitOfWork::class)->setPublic(true);
        if ($withQueryGuard) {
            $container
                ->register(BundleGuardedRepository::class, BundleGuardedRepository::class)
                ->setAutowired(true)
                ->setPublic(true);
        }
        if (null !== $extraGuardRepository) {
            $container->register($extraGuardRepository, $extraGuardRepository)->setAutowired(true)->setPublic(true);
        }
        $container->compile();
        if (null !== $postgres) {
            $container->set(Connection::class, $postgres);
        }

        return $container;
    }
}

final readonly class BundleGuardedRepository
{
    public function __construct(
        private InMemoryRowDatabase $database,
        #[DefineQueryGuard('orders_status', [
            new QueryGuardDependency(OrdersDefinition::class, 'order_id', ['status']),
        ])]
        private QueryGuard $statusGuard,
    ) {}

    public function status(string $id): string
    {
        return $this->statusGuard->observe(
            $id,
            fn(): string => $this->database->row('orders', 'order_id', $id)['status'],
        );
    }
}

final readonly class BundleInvalidGuardRepository
{
    public function __construct(
        #[DefineQueryGuard('invalid', [
            new QueryGuardDependency(OrdersDefinition::class, 'order_id', ['missing']),
        ])]
        private QueryGuard $guard,
    ) {}
}

final readonly class BundleWrongGuardParameter
{
    public function __construct(
        #[DefineQueryGuard('invalid_type', [
            new QueryGuardDependency(OrdersDefinition::class, 'order_id'),
        ])]
        private string $guard,
    ) {}
}

final readonly class BundleLinesGuardRepository
{
    public function __construct(
        #[DefineQueryGuard('order_lines', [
            new QueryGuardDependency(OrderLinesDefinition::class, 'order_id'),
        ])]
        private QueryGuard $guard,
    ) {}

    public function observe(string $owner): void
    {
        $this->guard->observe($owner, static fn(): array => []);
    }
}

final readonly class BundleConflictingGuardRepository
{
    public function __construct(
        #[DefineQueryGuard('orders_status', [
            new QueryGuardDependency(OrdersDefinition::class, 'order_id', ['note']),
        ])]
        private QueryGuard $guard,
    ) {}
}

final class BundleLinesWithoutDependency implements MappedRowSetDefinition
{
    public function mapping(): MappedRowSetMapping
    {
        $lines = new OrderLinesDefinition()->mapping();

        return new MappedRowSetMapping($lines->table, $lines->columns, $lines->keyColumns, ['order_id']);
    }
}

#[State(table: 'missing_orders')]
final readonly class BundleUnmappedState
{
    public function __construct(
        #[StateId(column: 'order_id')]
        public string $id,
    ) {}
}

#[State(table: 'order_lines')]
final readonly class BundleWrongKeyState
{
    public function __construct(
        #[StateId(column: 'line_id')]
        public string $id,
    ) {}
}

#[State(table: 'orders')]
final readonly class BundleAlternateOrderKey
{
    public function __construct(
        #[StateId(column: 'status')]
        public string $id,
    ) {}
}

#[State(table: 'orders')]
final readonly class BundleWrongKeyTypeState
{
    public function __construct(
        #[StateId(column: 'order_id')]
        public int $id,
    ) {}
}

#[State(table: 'orders')]
final readonly class BundleUnknownEmbeddedColumnState
{
    public function __construct(
        #[StateId(column: 'order_id')]
        public string $id,
        #[StateEmbedded(prefix: 'missing_')]
        public BundleEmbeddedValue $embedded,
    ) {}
}

final readonly class BundleEmbeddedValue
{
    public function __construct(
        public string $value,
    ) {}
}

#[State(table: 'orders')]
final readonly class BundleWrongNullabilityState
{
    public function __construct(
        #[StateId(column: 'order_id')]
        public string $id,
        public string $note,
    ) {}
}

#[State(table: 'orders')]
final readonly class BundleWrongCollectionType
{
    /** @param list<BundleEmbeddedValue> $lines */
    public function __construct(
        #[StateId(column: 'order_id')]
        public string $id,
        #[StateCollection(itemClass: OrderLineState::class)]
        public array $lines,
    ) {}
}

enum BundleStatus: string
{
    case Pending = 'pending';
    case Shipped = 'shipped';
}

#[State(table: 'orders')]
final readonly class BundleOrderEnumView
{
    public function __construct(
        #[StateId(column: 'order_id')]
        public string $id,
        public BundleStatus $status,
        #[StateColumn(optimistic: false)]
        public ?string $note,
    ) {}
}
