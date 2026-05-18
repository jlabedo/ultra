<?php

declare(strict_types=1);

namespace Ultra\UnitOfWork_Bundle\Tests;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\Attribute\AsTaggedItem;
use Ultra\EventStore\AppendCondition;
use Ultra\EventStore\Checkpoint;
use Ultra\EventStore\Event;
use Ultra\EventStore\EventStore;
use Ultra\EventStore\EventStoreUnitOfWork;
use Ultra\EventStore\LoadResult;
use Ultra\EventStore\StreamQuery;
use Ultra\UnitOfWork\CompositeUnitOfWork;
use Ultra\UnitOfWork\NoTransactionManager;
use Ultra\UnitOfWork\TransactionManager;
use Ultra\UnitOfWork\TransactionalUnitOfWorkRunner;
use Ultra\UnitOfWork\UnitOfWork;
use Ultra\UnitOfWork\UnitOfWorkRunner;
use Ultra\UnitOfWork_Bundle\UnitOfWorkBundle;
use Ultra\Testing\UltraLight;
use Ultra\Testing\UltraLightContainer;
use Ultra\Testing\UltraLightContainerFactoryInterface;

final class RecordingEventStore implements EventStore
{
    /** @var list<list<Event>> */
    public array $appendCalls = [];

    public function load(
        StreamQuery $query,
        ?Checkpoint $from = null,
        ?Checkpoint $to = null,
        int $batchSize = 1000,
    ): LoadResult {
        return new LoadResult([], $to ?? $from ?? Checkpoint::beginning(), $query);
    }

    public function append(array $events, AppendCondition ...$appendConditions): void
    {
        $this->appendCalls[] = $events;
    }
}

final class UnitOfWorkCallRecorder
{
    /** @var list<string> */
    public static array $calls = [];

    public static function reset(): void
    {
        self::$calls = [];
    }
}

final class AutoconfiguredUnitOfWork implements UnitOfWork
{
    public function flush(): void
    {
        UnitOfWorkCallRecorder::$calls[] = 'autoconfigured:flush';
    }

    public function clear(): void
    {
        UnitOfWorkCallRecorder::$calls[] = 'autoconfigured:clear';
    }
}

final class TaggedUnitOfWork implements UnitOfWork
{
    public function flush(): void
    {
        UnitOfWorkCallRecorder::$calls[] = 'tagged:flush';
    }

    public function clear(): void
    {
        UnitOfWorkCallRecorder::$calls[] = 'tagged:clear';
    }
}

#[AsTaggedItem(priority: 50)]
final class HighPriorityUnitOfWork implements UnitOfWork
{
    public function flush(): void
    {
        UnitOfWorkCallRecorder::$calls[] = 'high:flush';
    }

    public function clear(): void
    {
        UnitOfWorkCallRecorder::$calls[] = 'high:clear';
    }
}

/**
 * @internal
 */
#[CoversClass(UnitOfWorkBundle::class)]
final class UnitOfWorkBundleTest extends TestCase
{
    protected function setUp(): void
    {
        UnitOfWorkCallRecorder::reset();
    }

    public function test_bundle_decorates_the_event_store_and_aggregates_tagged_unit_of_works(): void
    {
        $container = $this->ultraLight()->boot();

        $eventStore = $container->eventStore();
        $unitOfWork = $container->unitOfWork();
        $innerEventStore = $container->recordingEventStore();

        self::assertInstanceOf(EventStoreUnitOfWork::class, $eventStore);
        self::assertInstanceOf(CompositeUnitOfWork::class, $unitOfWork);

        $eventStore->append([
            new Event('course.created', ['name' => 'Math 101'], ['courseId' => 'course-1']),
        ]);

        $unitOfWork->flush();

        self::assertCount(1, $innerEventStore->appendCalls);
        self::assertCount(1, $innerEventStore->appendCalls[0]);
        self::assertSame([
            'high:flush',
            'autoconfigured:flush',
            'tagged:flush',
        ], UnitOfWorkCallRecorder::$calls);
    }

    public function test_bundle_clear_delegates_to_every_registered_unit_of_work(): void
    {
        $container = $this->ultraLight()->boot();

        $eventStore = $container->eventStore();
        $unitOfWork = $container->unitOfWork();
        $innerEventStore = $container->recordingEventStore();

        self::assertInstanceOf(EventStoreUnitOfWork::class, $eventStore);

        $eventStore->append([
            new Event('course.created', ['name' => 'Math 101'], ['courseId' => 'course-1']),
        ]);

        $unitOfWork->clear();
        $eventStore->flush();

        self::assertSame([], $innerEventStore->appendCalls);
        self::assertSame([
            'high:clear',
            'autoconfigured:clear',
            'tagged:clear',
        ], UnitOfWorkCallRecorder::$calls);
    }

    public function test_bundle_registers_a_noop_transaction_manager_by_default(): void
    {
        $container = $this->ultraLight()->boot();

        self::assertInstanceOf(NoTransactionManager::class, $container->transactionManager());
    }

    public function test_bundle_registers_the_transactional_unit_of_work_runner(): void
    {
        $container = $this->ultraLight()->boot();

        self::assertInstanceOf(TransactionalUnitOfWorkRunner::class, $container->unitOfWorkRunner());
    }

    /**
     * @return UltraLight<UnitOfWorkTestContainer>
     */
    private function ultraLight(): UltraLight
    {
        /** @var UltraLightContainerFactoryInterface<UnitOfWorkTestContainer> $containerFactory */
        $containerFactory = new class implements UltraLightContainerFactoryInterface {
            public function create(\Symfony\Component\DependencyInjection\Container $container): UnitOfWorkTestContainer
            {
                return new UnitOfWorkTestContainer($container);
            }
        };

        return new UltraLight()
            ->withBuildDir(dirname(__DIR__, 3).'/var/unit_of_work_bundle_tests')
            ->withContainerFactory($containerFactory)
            ->withBundle(new UnitOfWorkBundle())
            ->withClass(
                RecordingEventStore::class,
                AutoconfiguredUnitOfWork::class,
                TaggedUnitOfWork::class,
                HighPriorityUnitOfWork::class,
            )
            ->withAlias(EventStore::class, RecordingEventStore::class)
            ->withAlias('test.event_store', EventStore::class)
            ->withAlias('test.recording_event_store', RecordingEventStore::class)
            ->withAlias('test.transaction_manager', TransactionManager::class)
            ->withAlias('test.unit_of_work', UnitOfWork::class)
            ->withAlias('test.unit_of_work_runner', UnitOfWorkRunner::class)
        ;
    }
}

final readonly class UnitOfWorkTestContainer extends UltraLightContainer
{
    public function eventStore(): EventStore
    {
        return $this->get('test.event_store');
    }

    public function recordingEventStore(): RecordingEventStore
    {
        return $this->get('test.recording_event_store');
    }

    public function unitOfWork(): UnitOfWork
    {
        return $this->get('test.unit_of_work');
    }

    public function transactionManager(): TransactionManager
    {
        return $this->get('test.transaction_manager');
    }

    public function unitOfWorkRunner(): UnitOfWorkRunner
    {
        return $this->get('test.unit_of_work_runner');
    }
}
