<?php

declare(strict_types=1);

namespace Ultra\UnitOfWork\Tests;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\DependencyInjection\Attribute\AsTaggedItem;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Ultra\Container\ContainerExtension;
use Ultra\EventStore\Event;
use Ultra\EventStore\EventStore;
use Ultra\EventStore\EventStoreUnitOfWork;
use Ultra\UnitOfWork\CompositeUnitOfWork;
use Ultra\UnitOfWork\NoTransactionManager;
use Ultra\UnitOfWork\TagNames;
use Ultra\UnitOfWork\Tests\Support\CallLog;
use Ultra\UnitOfWork\Tests\Support\ContainerTestCase;
use Ultra\UnitOfWork\Tests\Support\RecordingEventStore;
use Ultra\UnitOfWork\TransactionalUnitOfWorkRunner;
use Ultra\UnitOfWork\UnitOfWork;
use Ultra\UnitOfWork\UnitOfWorkExtension;
use Ultra\UnitOfWork\UnitOfWorkRunner;

#[CoversClass(UnitOfWorkExtension::class)]
final class ContainerCompositionTest extends ContainerTestCase
{
    public function test_assembly_orders_autoconfigured_and_explicitly_tagged_participants_and_decorates_the_store(): void
    {
        $log = new CallLog();
        $container = $this
            ->ultraLight()
            ->withService($log)
            ->withClass(AutoconfiguredParticipant::class, HighPriorityParticipant::class)
            ->withExtension(new class implements ContainerExtension {
                public function build(ContainerBuilder $container): void
                {
                    $container
                        ->register(ExplicitParticipant::class)
                        ->setAutowired(true)
                        ->addTag(TagNames::UNIT_OF_WORK);
                }
            })
            ->boot();
        /** @var EventStoreUnitOfWork $eventStore */
        $eventStore = $container->get('test.event_store');
        /** @var UnitOfWork $unitOfWork */
        $unitOfWork = $container->get('test.unit_of_work');
        /** @var RecordingEventStore $inner */
        $inner = $container->get('test.inner_event_store');
        $event = new Event('unit_of_work.created', [], ['id' => 'composition']);

        static::assertInstanceOf(EventStoreUnitOfWork::class, $eventStore);
        static::assertInstanceOf(CompositeUnitOfWork::class, $unitOfWork);
        $eventStore->append([$event]);
        $unitOfWork->flush();
        static::assertSame([[$event]], $inner->appendCalls);
        static::assertSame(['high:flush', 'autoconfigured:flush', 'explicit:flush'], $log->entries);

        $eventStore->append([$event]);
        $unitOfWork->clear();
        $eventStore->flush();
        static::assertSame([[$event]], $inner->appendCalls);
        static::assertSame(
            [
                'high:flush',
                'autoconfigured:flush',
                'explicit:flush',
                'high:clear',
                'autoconfigured:clear',
                'explicit:clear',
            ],
            $log->entries,
        );
    }

    #[DataProvider('eventStorePresence')]
    public function test_default_services_offer_the_runner_and_noop_transaction_boundary(bool $withEventStore): void
    {
        $container = $this->ultraLight($withEventStore)->boot();
        static::assertInstanceOf(NoTransactionManager::class, $container->get('test.transactions'));
        static::assertInstanceOf(TransactionalUnitOfWorkRunner::class, $container->get('test.runner'));
    }

    /** @return iterable<string, array{bool}> */
    public static function eventStorePresence(): iterable
    {
        yield 'with an event store' => [true];
        yield 'without an event store' => [false];
    }

    public function test_without_an_event_store_the_runner_flushes_and_clears_other_participants(): void
    {
        $log = new CallLog();
        $container = $this
            ->ultraLight(withEventStore: false)
            ->withService($log)
            ->withClass(AutoconfiguredParticipant::class)
            ->boot();
        /** @var UnitOfWorkRunner $runner */
        $runner = $container->get('test.runner');

        $runner->run(static function () use ($log): void {
            $log->entries[] = 'work';
        });

        static::assertSame(['work', 'autoconfigured:flush', 'autoconfigured:clear'], $log->entries);
        static::assertFalse($container->has(EventStore::class));
        static::assertFalse($container->has(EventStoreUnitOfWork::class));
    }

    #[DataProvider('lateEventStoreBindings')]
    public function test_event_stores_registered_after_the_extension_are_decorated_and_flushed(bool $alias): void
    {
        $container = $this
            ->ultraLight(withEventStore: false)
            ->withAlias('test.event_store', EventStore::class)
            ->withAlias('test.inner_event_store', EventStoreUnitOfWork::class . '.inner')
            ->withExtension(new class($alias) implements ContainerExtension {
                public function __construct(
                    private bool $alias,
                ) {}

                public function build(ContainerBuilder $container): void
                {
                    if ($this->alias) {
                        $container->register(RecordingEventStore::class, RecordingEventStore::class);
                        $container->setAlias(EventStore::class, RecordingEventStore::class);

                        return;
                    }

                    $container->register(EventStore::class, RecordingEventStore::class);
                }
            })
            ->boot();
        /** @var EventStoreUnitOfWork $store */
        $store = $container->get('test.event_store');
        /** @var RecordingEventStore $inner */
        $inner = $container->get('test.inner_event_store');
        /** @var UnitOfWorkRunner $runner */
        $runner = $container->get('test.runner');
        $event = new Event('unit_of_work.created', [], ['id' => 'late-store']);

        static::assertInstanceOf(EventStoreUnitOfWork::class, $store);
        $runner->run(static function () use ($store, $event): void {
            $store->append([$event]);
        });
        static::assertSame([[$event]], $inner->appendCalls);
        static::assertFalse($store->hasPendingChanges());
    }

    /** @return iterable<string, array{bool}> */
    public static function lateEventStoreBindings(): iterable
    {
        yield 'definition' => [false];
        yield 'alias' => [true];
    }
}

abstract class OrderedParticipant implements UnitOfWork
{
    protected const string NAME = '';

    public function __construct(
        private CallLog $log,
    ) {}

    public function hasPendingChanges(): bool
    {
        return true;
    }

    public function flush(): void
    {
        $this->log->entries[] = static::NAME . ':flush';
    }

    public function clear(): void
    {
        $this->log->entries[] = static::NAME . ':clear';
    }
}

final class AutoconfiguredParticipant extends OrderedParticipant
{
    protected const string NAME = 'autoconfigured';
}

#[AsTaggedItem(priority: 50)]
final class HighPriorityParticipant extends OrderedParticipant
{
    protected const string NAME = 'high';
}

final class ExplicitParticipant extends OrderedParticipant
{
    protected const string NAME = 'explicit';
}
