<?php

declare(strict_types=1);

namespace Ultra\UnitOfWork\Tests;

use PHPUnit\Framework\Attributes\CoversClass;
use Symfony\Component\DependencyInjection\Attribute\AsTaggedItem;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Ultra\Container\ContainerExtension;
use Ultra\EventStore\Event;
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

    public function test_default_services_offer_the_runner_and_noop_transaction_boundary(): void
    {
        $container = $this->ultraLight()->boot();
        static::assertInstanceOf(NoTransactionManager::class, $container->get('test.transactions'));
        static::assertInstanceOf(TransactionalUnitOfWorkRunner::class, $container->get('test.runner'));
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
