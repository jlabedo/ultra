<?php

declare(strict_types=1);

namespace Ultra\UnitOfWork_Bundle\Tests;

use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Definition;
use Ultra\EventStore\Event;
use Ultra\EventStore\EventStore;
use Ultra\EventStore\EventStoreUnitOfWork;
use Ultra\EventStore\LoadResult;
use Ultra\EventStore\QueryVersion;
use Ultra\EventStore\StreamQuery;
use Ultra\UnitOfWork\CompositeUnitOfWork;
use Ultra\UnitOfWork\UnitOfWork;
use Ultra\UnitOfWork_Bundle\TagNames;
use Ultra\UnitOfWork_Bundle\UnitOfWorkBundle;

final class RecordingEventStore implements EventStore
{
    /** @var list<list<Event>> */
    public array $appendCalls = [];

    public function load(StreamQuery $query): LoadResult
    {
        return new LoadResult([], new QueryVersion(0, $query));
    }

    public function append(array $events, QueryVersion ...$expectedVersions): void
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

final class UnitOfWorkBundleTest extends TestCase
{
    protected function setUp(): void
    {
        UnitOfWorkCallRecorder::reset();
    }

    public function testBundleDecoratesTheEventStoreAndAggregatesTaggedUnitOfWorks(): void
    {
        $container = $this->createContainer();

        $eventStore = $container->get(EventStore::class);
        $unitOfWork = $container->get(UnitOfWork::class);
        $innerEventStore = $container->get(RecordingEventStore::class);

        self::assertInstanceOf(EventStoreUnitOfWork::class, $eventStore);
        self::assertInstanceOf(CompositeUnitOfWork::class, $unitOfWork);

        $eventStore->append([
            new Event('course.created', ['name' => 'Math 101'], ['courseId' => 'course-1']),
        ]);

        $unitOfWork->flush();

        self::assertCount(1, $innerEventStore->appendCalls);
        self::assertCount(1, $innerEventStore->appendCalls[0]);
        self::assertEqualsCanonicalizing([
            'autoconfigured:flush',
            'tagged:flush',
        ], UnitOfWorkCallRecorder::$calls);
    }

    public function testBundleClearDelegatesToEveryRegisteredUnitOfWork(): void
    {
        $container = $this->createContainer();

        $eventStore = $container->get(EventStore::class);
        $unitOfWork = $container->get(UnitOfWork::class);
        $innerEventStore = $container->get(RecordingEventStore::class);

        self::assertInstanceOf(EventStoreUnitOfWork::class, $eventStore);

        $eventStore->append([
            new Event('course.created', ['name' => 'Math 101'], ['courseId' => 'course-1']),
        ]);

        $unitOfWork->clear();
        $eventStore->flush();

        self::assertSame([], $innerEventStore->appendCalls);
        self::assertEqualsCanonicalizing([
            'autoconfigured:clear',
            'tagged:clear',
        ], UnitOfWorkCallRecorder::$calls);
    }

    private function createContainer(): ContainerBuilder
    {
        $container = new ContainerBuilder();
        (new UnitOfWorkBundle())->build($container);

        $container->register(RecordingEventStore::class, RecordingEventStore::class)
            ->setPublic(true)
        ;
        $container->setAlias(EventStore::class, RecordingEventStore::class)->setPublic(true);

        $container->setDefinition(
            AutoconfiguredUnitOfWork::class,
            (new Definition(AutoconfiguredUnitOfWork::class))
                ->setAutoconfigured(true)
        );
        $container->setDefinition(
            TaggedUnitOfWork::class,
            (new Definition(TaggedUnitOfWork::class))
                ->addTag(TagNames::UNIT_OF_WORK)
        );
        $container->getAlias(UnitOfWork::class)->setPublic(true);

        $container->compile();

        return $container;
    }
}
