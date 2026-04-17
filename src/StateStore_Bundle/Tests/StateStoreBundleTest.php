<?php

declare(strict_types=1);

namespace Ultra\StateStore_Bundle\Tests;

use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\ChildDefinition;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Definition;
use Symfony\Component\DependencyInjection\Reference;
use Ultra\EventStore\Checkpoint;
use Ultra\EventStore\EventStore;
use Ultra\EventStore\LoadResult;
use Ultra\EventStore\AppendCondition;
use Ultra\EventStore\StreamQuery;
use Ultra\MappedTable\InMemoryMappedTableAdapter;
use Ultra\MappedTable\InMemoryRowDatabase;
use Ultra\MappedTable\MappedTableAdapter;
use Ultra\Mapper_Bundle\MapperBundle;
use Ultra\Mapper_Bundle\TagNames as MapperTagNames;
use Ultra\StateStore\Attribute\State;
use Ultra\StateStore\Attribute\StateColumn;
use Ultra\StateStore\Attribute\StateId;
use Ultra\StateStore\StateStore;
use Ultra\StateStore_Bundle\StateStoreBundle;
use Ultra\StateStore_Bundle\TagNames;
use Ultra\UnitOfWork\UnitOfWork;
use Ultra\UnitOfWork_Bundle\UnitOfWorkBundle;

final class NullEventStore implements EventStore
{
    public function load(
        StreamQuery $query,
        ?Checkpoint $from = null,
        ?Checkpoint $to = null,
        int $batchSize = 1000,
    ): LoadResult
    {
        return new LoadResult([], $to ?? $from ?? Checkpoint::beginning());
    }

    public function append(array $events, AppendCondition ...$appendConditions): void {}
}

#[State(table: 'orders', idColumn: 'order_id')]
final readonly class BundleOrderState
{
    public function __construct(
        #[StateId]
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

#[State(table: 'orders', idColumn: 'order_id')]
final readonly class BundleOrderStatusView
{
    public function __construct(
        #[StateId]
        public string $orderId,
        #[StateColumn('status')]
        public string $status,
    ) {}

    public function withStatus(string $status): self
    {
        return new self($this->orderId, $status);
    }
}

/**
 * @internal
 *
 * @coversNothing
 */
final class StateStoreBundleTest extends TestCase
{
    public function test_bundle_registers_state_store_and_shared_mapped_table_services(): void
    {
        $container = $this->createContainer();
        $database = $container->get(InMemoryRowDatabase::class);
        $store = $container->get(StateStore::class);
        $unitOfWork = $container->get(UnitOfWork::class);

        self::assertInstanceOf(StateStore::class, $store);

        $store->save(new BundleOrderState('order-123', 'pending', 'draft'));
        $unitOfWork->flush();

        self::assertSame([
            'order_id' => 'order-123',
            'status' => 'pending',
            'note' => 'draft',
        ], $database->row('orders', 'order_id', 'order-123', ['status', 'note']));

        $view = $store->load(BundleOrderStatusView::class, 'order-123');
        self::assertInstanceOf(BundleOrderStatusView::class, $view);

        $store->expect($view);
        $store->save(new BundleOrderState('order-123', 'shipped', 'draft'));
        $unitOfWork->flush();

        self::assertSame([
            'order_id' => 'order-123',
            'status' => 'shipped',
            'note' => 'draft',
        ], $database->row('orders', 'order_id', 'order-123', ['status', 'note']));
    }

    public function test_bundle_autoconfiguration_marks_states_as_normalizable_resources(): void
    {
        $container = new ContainerBuilder();
        new StateStoreBundle()->build($container);

        $definition = new ChildDefinition('');
        $configurator = $container->getAttributeAutoconfigurators()[State::class][0];
        $configurator(
            $definition,
            new State('orders', 'order_id'),
            new \ReflectionClass(BundleOrderState::class),
        );

        self::assertSame([[
            'class' => BundleOrderState::class,
        ]], $definition->getTag(TagNames::STATE));
        self::assertSame([[
            'class' => BundleOrderState::class,
        ]], $definition->getTag(MapperTagNames::NORMALIZABLE));
    }

    private function createContainer(): ContainerBuilder
    {
        $container = new ContainerBuilder();
        $container->setParameter('kernel.build_dir', sys_get_temp_dir().'/ultra_state_store_bundle_test');
        new MapperBundle()->build($container);
        new UnitOfWorkBundle()->build($container);
        new StateStoreBundle()->build($container);

        $container->register(NullEventStore::class, NullEventStore::class)
            ->setPublic(true)
        ;
        $container->setAlias(EventStore::class, NullEventStore::class)->setPublic(true);

        $container->register(InMemoryRowDatabase::class, InMemoryRowDatabase::class)
            ->setPublic(true)
        ;
        $container->register(InMemoryMappedTableAdapter::class, InMemoryMappedTableAdapter::class)
            ->setArguments([new Reference(InMemoryRowDatabase::class)])
            ->setPublic(true)
        ;
        $container->setAlias(MappedTableAdapter::class, InMemoryMappedTableAdapter::class)->setPublic(true);

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

        $container->getAlias(UnitOfWork::class)->setPublic(true);
        $container->compile();

        return $container;
    }
}
