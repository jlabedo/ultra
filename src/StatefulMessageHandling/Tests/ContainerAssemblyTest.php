<?php

declare(strict_types=1);

namespace Ultra\StatefulMessageHandling\Tests;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use Symfony\Component\DependencyInjection\Dumper\PhpDumper;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Reference;
use Ultra\EventSourcedState\Tests\Example\Event\CourseCreated;
use Ultra\EventStore\InMemoryEventStore;
use Ultra\EventStore\StreamQuery;
use Ultra\MappedTable\InMemoryMappedRowSetAdapterFactory;
use Ultra\MappedTable\InMemoryRowDatabase;
use Ultra\StatefulMessageHandling\Tests\Support\DecisionTestCase;

#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(false)]
final class ContainerAssemblyTest extends DecisionTestCase
{
    #[DataProvider('families')]
    public function test_dumped_containers_assemble_each_available_persistence_family(string $family): void
    {
        $builder = new ContainerBuilder();
        $builder->setParameter('kernel.build_dir', $this->buildDirectory());
        $builder->setAlias('test.mediator', \Ultra\MessageHandling\MediatorInterface::class)->setPublic(true);
        $builder->register(DecisionService::class, DecisionService::class);
        $extensions = [new \Ultra\MessageHandling\MessageHandlingExtension(), new \Ultra\Mapper_Bundle\MapperExtension(), new \Ultra\StatefulMessageHandling\StatefulMessageHandlingExtension()];
        $classes = [];
        if ('stored' !== $family) {
            $classes = [CourseCreated::class, \Ultra\EventSourcedState\Tests\Example\State\CourseCapacityState::class];
            $builder->register(InMemoryEventStore::class, InMemoryEventStore::class)->setPublic(true);
            $builder->setAlias(\Ultra\EventStore\EventStore::class, InMemoryEventStore::class);
            $extensions[] = new \Ultra\Events\EventsExtension();
            $extensions[] = new \Ultra\EventSourcedState\EventSourcedStateExtension();
        }
        if ('events' !== $family) {
            $classes[] = DecisionOrderState::class;
            $builder->register(InMemoryRowDatabase::class, InMemoryRowDatabase::class)->setPublic(true);
            $builder->register(InMemoryMappedRowSetAdapterFactory::class, InMemoryMappedRowSetAdapterFactory::class)->setArguments([new Reference(InMemoryRowDatabase::class)]);
            $builder->setAlias(\Ultra\MappedTable\MappedRowSetAdapterFactory::class, InMemoryMappedRowSetAdapterFactory::class);
            $builder->setAlias('test.state_store', \Ultra\StateStore\StateStore::class)->setPublic(true);
            $extensions[] = new \Ultra\StateStore_Bundle\StateStoreExtension();
        }
        $classes[] = match ($family) {
            'events' => InspectDecisionCourse::class,
            'stored' => InspectDecisionOrder::class,
            'mixed' => InspectMixedDecision::class,
        };
        if ('mixed' === $family) {
            $classes[] = InspectDecisionCourse::class;
            $classes[] = InspectDecisionOrder::class;
        }
        foreach ($classes as $class) {
            $builder->register($class, $class)->setAutoconfigured(true)->setAutowired(true);
        }
        foreach ($extensions as $extension) {
            $extension->build($builder);
        }
        $builder->compile();
        $class = 'StatefulContainer_'.bin2hex(random_bytes(8));
        $file = $builder->getParameter('kernel.build_dir').'/container.php';
        file_put_contents($file, (new PhpDumper($builder))->dump(['class' => $class]));
        require $file;
        $container = new $class();
        $event = new CourseCreated('course', 'Math', 12);
        $state = new DecisionOrderState('order', 'paid');
        $command = match ($family) {
            'events' => new InspectDecisionCourse('course', $event),
            'stored' => new InspectDecisionOrder('order', $state),
            'mixed' => new InspectMixedDecision('course', 'order', [$event, $state]),
        };
        self::assertEquals('mixed' === $family ? [$event, $state] : ('events' === $family ? $event : $state), $container->get('test.mediator')->send($command));
        if ('stored' !== $family) {
            self::assertCount(1, $container->get(InMemoryEventStore::class)->load(StreamQuery::all())->events);
        }
        if ('events' !== $family) {
            self::assertEquals($state, $container->get('test.state_store')->load(DecisionOrderState::class, 'order'));
        }
        if ('mixed' === $family) {
            $foreignState = new DecisionOrderState('foreign', 'ignored');
            self::assertSame($foreignState, $container->get('test.mediator')->send(new InspectDecisionCourse('course', $foreignState)));
            self::assertNull($container->get('test.state_store')->load(DecisionOrderState::class, 'foreign'));
            $foreignEvent = new CourseCreated('foreign', 'Ignored', 1);
            self::assertSame($foreignEvent, $container->get('test.mediator')->send(new InspectDecisionOrder('order', $foreignEvent)));
            self::assertCount(1, $container->get(InMemoryEventStore::class)->load(StreamQuery::all())->events);
        }

    }

    public static function families(): iterable
    {
        yield 'event-sourced only' => ['events'];
        yield 'stored only' => ['stored'];
        yield 'mixed' => ['mixed'];
    }
}
