<?php

declare(strict_types=1);

namespace Ultra\StatefulMessageHandling\Tests;

use PHPUnit\Framework\Attributes\DataProvider;
use Ultra\EventSourcedState\Tests\Example\Event\CourseCreated;
use Ultra\EventStore\DomainIdentifier;
use Ultra\EventStore\InMemoryEventStore;
use Ultra\EventStore\StreamQuery;
use Ultra\MappedTable\InMemoryRowDatabase;
use Ultra\MessageHandling\EventBusInterface;
use Ultra\StatefulMessageHandling\Tests\Example\Command\CreateCourseCommand;
use Ultra\StatefulMessageHandling\Tests\Support\DecisionTestCase;
use Ultra\StateStore\Exception\StateNotFoundException;

final class StateInjectionTest extends DecisionTestCase
{
    #[DataProvider('handlerOrganizations')]
    public function test_loaded_states_are_injected_into_self_and_separate_handlers(
        string $family,
        bool $separate,
    ): void {
        if ('events' === $family) {
            $container = $this->courseApplication(
                CreateCourseWithSeparateHandlerCommand::class,
                CreateCourseWithSeparateHandler::class,
            )->boot();
            $command = $separate
                ? new CreateCourseWithSeparateHandlerCommand('course', 'Math', 12)
                : new CreateCourseCommand('course', 'Math', 12);
            static::assertEquals(
                [new CourseCreated('course', 'Math', 12)],
                $container->get('test.mediator')->send($command),
            );
            static::assertSame(
                'Math',
                $container->get(InMemoryEventStore::class)->load(StreamQuery::forStream(new DomainIdentifier(
                    'course',
                    'course',
                )))->events[0]->payload['name'],
            );
            $this->expectException(\InvalidArgumentException::class);
            $container->get('test.mediator')->send(new CreateCourseCommand('course', 'Other', 5));
        } else {
            $container = $this->storedApplication(
                ShipDecisionOrderCommand::class,
                ConfirmDecisionOrderCommand::class,
                ConfirmDecisionOrderHandler::class,
            )->boot();
            $container->get(InMemoryRowDatabase::class)->seedRow('decision_orders', 'order_id', [
                'order_id' => 'order',
                'status' => 'paid',
            ]);
            $command = $separate ? new ConfirmDecisionOrderCommand('order') : new ShipDecisionOrderCommand('order');
            $expected = new DecisionOrderState('order', $separate ? 'confirmed' : 'shipped');
            static::assertEquals($expected, $container->get('test.mediator')->send($command));
            static::assertEquals($expected, $container->get('test.state_store')->load(
                DecisionOrderState::class,
                'order',
            ));
        }
    }

    public static function handlerOrganizations(): iterable
    {
        yield 'event-sourced self' => ['events', false];
        yield 'event-sourced separate' => ['events', true];
        yield 'stored self' => ['stored', false];
        yield 'stored separate' => ['stored', true];
    }

    #[DataProvider('nestedBindings')]
    public function test_nested_properties_bind_the_requested_identity(string $kind): void
    {
        if ('events' === $kind) {
            $container = $this->courseApplication(CreateNestedCourseCommand::class)->boot();
            static::assertEquals(
                [new CourseCreated('nested', 'Math', 12)],
                $container->get('test.mediator')->send(
                    new CreateNestedCourseCommand(new NestedCourseReference('nested'), 'Math', 12),
                ),
            );
        } else {
            $container = $this->storedApplication(
                AcceptDecisionTermsCommand::class,
                GetDecisionTermsStatusQuery::class,
            )->boot();
            $container->get(InMemoryRowDatabase::class)->seedRow('decision_terms', 'terms_id', [
                'terms_id' => 'terms',
                'status' => 'published',
            ]);
            $id = new DecisionAcceptanceId('identity', 'terms');
            if ('reader' === $kind) {
                $container->get('test.mediator')->send(new AcceptDecisionTermsCommand($id));
                $this->flush($container);
            }
            $command = 'reader' === $kind ? new GetDecisionTermsStatusQuery($id) : new AcceptDecisionTermsCommand($id);
            static::assertEquals(
                'reader' === $kind ? ['published', 'accepted'] : new DecisionAcceptanceState($id, 'accepted'),
                $container->get('test.mediator')->send($command),
            );
            static::assertEquals(
                new DecisionAcceptanceState($id, 'accepted'),
                $container->get('test.state_store')->load(
                    DecisionAcceptanceState::class,
                    new DecisionAcceptanceId('identity', 'terms'),
                ),
            );
            if ('reader' === $kind) {
                static::assertFalse($container->get('test.state_tables')->hasPendingChanges());
            }
        }
    }

    public static function nestedBindings(): iterable
    {
        yield 'event-sourced decision' => ['events'];
        yield 'stored decision and composite identity' => ['stored'];
        yield 'stored reader' => ['reader'];
    }

    public function test_nullable_stored_states_allow_creation_and_readers_do_not_prepare_writes(): void
    {
        $container = $this->storedApplication(
            CreateDecisionOrderCommand::class,
            GetDecisionOrderStatusQuery::class,
        )->boot();
        $mediator = $container->get('test.mediator');
        static::assertNull($mediator->send(new GetDecisionOrderStatusQuery('order')));
        static::assertFalse($container->get('test.state_tables')->hasPendingChanges());
        static::assertEquals(
            new DecisionOrderState('order', 'pending'),
            $mediator->send(new CreateDecisionOrderCommand('order')),
        );
        $this->flush($container);
        static::assertSame('pending', $mediator->send(new GetDecisionOrderStatusQuery('order')));
        static::assertFalse($container->get('test.state_tables')->hasPendingChanges());
    }

    public function test_missing_required_stored_states_fail_before_the_decision(): void
    {
        $container = $this->storedApplication(ShipDecisionOrderCommand::class)->boot();
        $this->expectException(StateNotFoundException::class);
        $container->get('test.mediator')->send(new ShipDecisionOrderCommand('missing'));
    }

    #[DataProvider('persistenceFamilies')]
    public function test_independent_compilations_keep_their_own_services_and_states_after_file_cleanup(string $family): void
    {
        $command = static fn(mixed $result): object => match ($family) {
            'events' => new InspectDecisionCourse('course', $result),
            'stored' => new InspectDecisionOrder('order', $result),
            'mixed' => new InspectMixedDecision('course', 'order', $result),
        };
        $firstEvent = new CourseCreated('course', 'First', 12);
        $firstState = new DecisionOrderState('order', 'first');
        $firstResult = match ($family) {
            'events' => $firstEvent,
            'stored' => $firstState,
            'mixed' => [$firstEvent, $firstState],
        };
        $first = $this
            ->inspectedApplication($family)
            ->withService(new DecisionEventBus(), EventBusInterface::class)
            ->boot();
        $firstMediator = $first->get('test.mediator');
        static::assertSame($firstResult, $firstMediator->send($command($firstResult)));
        $firstDirectory = $first->getParameter('kernel.build_dir');
        static::assertDirectoryExists($firstDirectory);
        self::removeGeneratedFiles($firstDirectory);
        static::assertDirectoryDoesNotExist($firstDirectory);

        $second = $this
            ->inspectedApplication($family)
            ->withService(new DecisionEventBus(), EventBusInterface::class)
            ->boot();
        $secondMediator = $second->get('test.mediator');
        static::assertNotSame($first->container(), $second->container());
        static::assertNotSame($firstMediator::class, $secondMediator::class);
        static::assertNotSame($first->get(DecisionService::class), $second->get(DecisionService::class));
        if ('stored' !== $family) {
            static::assertSame([], $second->get(InMemoryEventStore::class)->load(StreamQuery::all())->events);
        }
        if ('events' !== $family) {
            static::assertNull($second->get('test.state_store')->load(DecisionOrderState::class, 'order'));
        }

        $secondEvent = new CourseCreated('course', 'Second', 24);
        $secondState = new DecisionOrderState('order', 'second');
        $secondResult = match ($family) {
            'events' => $secondEvent,
            'stored' => $secondState,
            'mixed' => [$secondEvent, $secondState],
        };
        static::assertSame($secondResult, $secondMediator->send($command($secondResult)));
        static::assertNull($firstMediator->send($command(null)));
        static::assertSame(2, $first->get(DecisionService::class)->calls);
        static::assertSame(1, $second->get(DecisionService::class)->calls);
        static::assertDirectoryDoesNotExist($firstDirectory);
        if ('stored' !== $family) {
            static::assertSame(
                ['First'],
                array_column(
                    array_column($first->get(InMemoryEventStore::class)->load(StreamQuery::all())->events, 'payload'),
                    'name',
                ),
            );
            static::assertSame(
                ['Second'],
                array_column(
                    array_column($second->get(InMemoryEventStore::class)->load(StreamQuery::all())->events, 'payload'),
                    'name',
                ),
            );
            static::assertSame([$firstEvent], $first->get(EventBusInterface::class)->events);
            static::assertSame([$secondEvent], $second->get(EventBusInterface::class)->events);
        }
        if ('events' !== $family) {
            static::assertEquals($firstState, $first->get('test.state_store')->load(
                DecisionOrderState::class,
                'order',
            ));
            static::assertEquals($secondState, $second->get('test.state_store')->load(
                DecisionOrderState::class,
                'order',
            ));
        }
    }

    public static function persistenceFamilies(): iterable
    {
        yield 'event-sourced' => ['events'];
        yield 'stored' => ['stored'];
        yield 'mixed' => ['mixed'];
    }
}
