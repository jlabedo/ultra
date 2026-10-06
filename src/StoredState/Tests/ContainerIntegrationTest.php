<?php

declare(strict_types=1);

namespace Ultra\StateStore\Tests;

use Symfony\Component\DependencyInjection\ChildDefinition;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Definition;
use Symfony\Component\DependencyInjection\Reference;
use Symfony\Component\DependencyInjection\Dumper\PhpDumper;
use Ultra\StateStore\Tests\Support\NullEventStore;
use Ultra\StateStore\Tests\Support\StateStoreTestCase;
use Ultra\EventStore\EventStore;
use Ultra\MappedTable\InMemoryMappedRowSetAdapterFactory;
use Ultra\MappedTable\InMemoryRowDatabase;
use Ultra\MappedTable\MappedRowSetAdapterFactory;
use Ultra\Mapper_Bundle\MapperExtension;
use Ultra\Mapper_Bundle\TagNames as MapperTagNames;
use Ultra\StateStore\Attribute\State;
use Ultra\StateStore\Attribute\StateColumn;
use Ultra\StateStore\Attribute\StateId;
use Ultra\StateStore\Attribute\DefineQueryGuard;
use Ultra\StateStore\QueryGuard;
use Ultra\StateStore\QueryGuardDefinition;
use Ultra\UnitOfWork\Exception\ConcurrencyException;
use Ultra\StateStore\GuardLockAdapter;
use Ultra\StateStore\InMemoryGuardLockAdapter;
use Ultra\StateStore\StateStore;
use Ultra\StateStore_Bundle\StateStoreExtension;
use Ultra\StateStore_Bundle\TagNames;
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
        $class = 'QueryGuardTestContainer_'.bin2hex(random_bytes(4));
        $php = (new PhpDumper($builder))->dump(['class' => $class]);
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

        self::assertSame('pending', $repository->status('one'));
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

    public function test_unknown_guard_properties_are_rejected_during_compilation(): void
    {
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('Unknown mapped property "missing"');
        $this->createContainer(extraGuardRepository: BundleInvalidGuardRepository::class);
    }

    public function test_conflicting_definitions_for_the_same_guard_are_rejected_during_compilation(): void
    {
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('Conflicting definitions for query guard "orders_status"');
        $this->createContainer(withQueryGuard: true, extraGuardRepository: BundleConflictingGuardRepository::class);
    }

    public function test_bundle_registers_state_store_and_shared_mapped_row_set_services(): void
    {
        $container = $this->createContainer(withGuardAdapter: false);
        $database = $container->get(InMemoryRowDatabase::class);
        $store = $container->get('test.state_store');
        $unitOfWork = $container->get(UnitOfWork::class);

        self::assertInstanceOf(StateStore::class, $store);

        $store->save(new BundleOrderState('order-123', 'pending', 'draft'));
        $unitOfWork->flush();

        self::assertSame([
            'status' => 'pending',
            'note' => 'draft',
        ], $database->row('orders', 'order_id', 'order-123', ['status', 'note']));

        $view = $store->load(BundleOrderStatusView::class, 'order-123');
        self::assertInstanceOf(BundleOrderStatusView::class, $view);

        $store->expect($view);
        $store->save(new BundleOrderState('order-123', 'shipped', 'draft'));
        $unitOfWork->flush();

        self::assertSame([
            'status' => 'shipped',
            'note' => 'draft',
        ], $database->row('orders', 'order_id', 'order-123', ['status', 'note']));
        $unitOfWork->clear();
        $state = new OrderWithLinesState('order-123', 'paid', [new OrderLineState('one', 'SKU', 2)]);
        $store->save($state);
        $this->flush($database, $unitOfWork);
        $unitOfWork->clear();
        self::assertEquals($state, $store->load(OrderWithLinesState::class, 'order-123'));
        self::assertSame(['quantity' => 2], $database->row('order_lines', 'line_id', 'one', ['quantity'], ['order_id' => 'order-123']));
        $this->expectException(\Ultra\StateStore\Exception\UnknownStateException::class);
        $store->load(OrderLineState::class, 'one');
    }

    public function test_bundle_autoconfiguration_marks_states_as_normalizable_resources(): void
    {
        $container = new ContainerBuilder();
        new StateStoreExtension()->build($container);

        $definition = new ChildDefinition('');
        $configurator = $container->getAttributeAutoconfigurators()[State::class][0];
        $configurator(
            $definition,
            new State('orders'),
            new \ReflectionClass(BundleOrderState::class),
        );

        self::assertSame([[
            'class' => BundleOrderState::class,
        ]], $definition->getTag(TagNames::STATE));
        self::assertSame([[
            'class' => BundleOrderState::class,
        ]], $definition->getTag(MapperTagNames::NORMALIZABLE));
    }

    private function createContainer(bool $withQueryGuard = false, ?string $extraGuardRepository = null, bool $withGuardAdapter = true): ContainerBuilder
    {
        $container = new ContainerBuilder();
        $container->setParameter('kernel.build_dir', sys_get_temp_dir().'/ultra_state_store_bundle_test');
        new MapperExtension()->build($container);
        new UnitOfWorkExtension()->build($container);
        new StateStoreExtension()->build($container);

        $container->register(NullEventStore::class, NullEventStore::class)->setPublic(true);
        $container->setAlias(EventStore::class, NullEventStore::class)->setPublic(true);

        $container->register(InMemoryRowDatabase::class, InMemoryRowDatabase::class)
            ->setPublic(true)
        ;
        $container->register(InMemoryMappedRowSetAdapterFactory::class, InMemoryMappedRowSetAdapterFactory::class)
            ->setArguments([new Reference(InMemoryRowDatabase::class)])
            ->setPublic(true)
        ;
        $container->setAlias(MappedRowSetAdapterFactory::class, InMemoryMappedRowSetAdapterFactory::class)->setPublic(true);
        if ($withGuardAdapter) {
            $container->register(GuardLockAdapter::class, InMemoryGuardLockAdapter::class)
                ->setArguments([new Reference(InMemoryRowDatabase::class)]);
        }
        $container->setAlias('test.state_store', StateStore::class)->setPublic(true);

        $container->setDefinition(
            BundleOrderState::class,
            new Definition(BundleOrderState::class)
                ->setAutoconfigured(true)
        );
        $container->setDefinition(
            BundleOrderStatusView::class,
            new Definition(BundleOrderStatusView::class)
                ->setAutoconfigured(true)
        );

        foreach ([OrderWithLinesState::class, OrderLineState::class] as $class) {
            $container->setDefinition($class, new Definition($class)->setAutoconfigured(true));
        }

        $container->getAlias(UnitOfWork::class)->setPublic(true);
        if ($withQueryGuard) {
            $container->register(BundleGuardedRepository::class, BundleGuardedRepository::class)
                ->setAutowired(true)
                ->setPublic(true);
        }
        if (null !== $extraGuardRepository) {
            $container->register($extraGuardRepository, $extraGuardRepository)->setAutowired(true);
        }
        $container->compile();

        return $container;
    }
}

final readonly class BundleGuardedRepository
{
    public function __construct(
        private InMemoryRowDatabase $database,
        #[DefineQueryGuard('orders_status', static function (QueryGuardDefinition $definition): void {
            $definition->watch(BundleOrderState::class, scopeBy: 'orderId')->whenPropertiesChange('status');
        })]
        private QueryGuard $statusGuard,
    ) {}

    public function status(string $id): string
    {
        return $this->statusGuard->observe($id, fn (): string => $this->database->row('orders', 'order_id', $id)['status']);
    }
}

final readonly class BundleInvalidGuardRepository
{
    public function __construct(
        #[DefineQueryGuard('invalid', static function (QueryGuardDefinition $definition): void {
            $definition->watch(BundleOrderState::class, scopeBy: 'orderId')->whenPropertiesChange('missing');
        })]
        private QueryGuard $guard,
    ) {}
}

final readonly class BundleConflictingGuardRepository
{
    public function __construct(
        #[DefineQueryGuard('orders_status', static function (QueryGuardDefinition $definition): void {
            $definition->watch(BundleOrderState::class, scopeBy: 'orderId')->whenPropertiesChange('note');
        })]
        private QueryGuard $guard,
    ) {}
}
