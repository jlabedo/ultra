<?php

declare(strict_types=1);

namespace Ultra\StatefulMessageHandling\Tests;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use Ultra\EventSourcedState\Tests\Example\Event\CourseCreated;
use Ultra\EventStore\DomainIdentifier;
use Ultra\EventStore\InMemoryEventStore;
use Ultra\EventStore\StreamQuery;
use Ultra\MappedTable\InMemoryRowDatabase;
use Ultra\StateStore\Exception\StateNotFoundException;
use Ultra\StatefulMessageHandling\Tests\Example\Command\CreateCourseCommand;
use Ultra\StatefulMessageHandling\Tests\Support\DecisionTestCase;

#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(false)]
final class StateInjectionTest extends DecisionTestCase
{
    #[DataProvider('handlerOrganizations')]
    public function test_loaded_states_are_injected_into_self_and_separate_handlers(string $family, bool $separate): void
    {
        if ('events' === $family) {
            $container = $this->courseApplication(CreateCourseWithSeparateHandlerCommand::class, CreateCourseWithSeparateHandler::class)->boot();
            $command = $separate ? new CreateCourseWithSeparateHandlerCommand('course', 'Math', 12) : new CreateCourseCommand('course', 'Math', 12);
            self::assertEquals([new CourseCreated('course', 'Math', 12)], $container->get('test.mediator')->send($command));
            self::assertSame('Math', $container->get(InMemoryEventStore::class)->load(StreamQuery::forStream(new DomainIdentifier('course', 'course')))->events[0]->payload['name']);
            $this->expectException(\InvalidArgumentException::class);
            $container->get('test.mediator')->send(new CreateCourseCommand('course', 'Other', 5));
        } else {
            $container = $this->storedApplication(ShipDecisionOrderCommand::class, ConfirmDecisionOrderCommand::class, ConfirmDecisionOrderHandler::class)->boot();
            $container->get(InMemoryRowDatabase::class)->seedRow('decision_orders', 'order_id', ['order_id' => 'order', 'status' => 'paid']);
            $command = $separate ? new ConfirmDecisionOrderCommand('order') : new ShipDecisionOrderCommand('order');
            $expected = new DecisionOrderState('order', $separate ? 'confirmed' : 'shipped');
            self::assertEquals($expected, $container->get('test.mediator')->send($command));
            self::assertEquals($expected, $container->get('test.state_store')->load(DecisionOrderState::class, 'order'));
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
            self::assertEquals([new CourseCreated('nested', 'Math', 12)], $container->get('test.mediator')->send(new CreateNestedCourseCommand(new NestedCourseReference('nested'), 'Math', 12)));
        } else {
            $container = $this->storedApplication(AcceptDecisionTermsCommand::class, GetDecisionTermsStatusQuery::class)->boot();
            $container->get(InMemoryRowDatabase::class)->seedRow('decision_terms', 'terms_id', ['terms_id' => 'terms', 'status' => 'published']);
            $id = new DecisionAcceptanceId('identity', 'terms');
            if ('reader' === $kind) {
                $container->get('test.mediator')->send(new AcceptDecisionTermsCommand($id));
                $this->flush($container);
            }
            $command = 'reader' === $kind ? new GetDecisionTermsStatusQuery($id) : new AcceptDecisionTermsCommand($id);
            self::assertEquals('reader' === $kind ? ['published', 'accepted'] : new DecisionAcceptanceState($id, 'accepted'), $container->get('test.mediator')->send($command));
            self::assertEquals(new DecisionAcceptanceState($id, 'accepted'), $container->get('test.state_store')->load(DecisionAcceptanceState::class, new DecisionAcceptanceId('identity', 'terms')));
            if ('reader' === $kind) {
                self::assertFalse($container->get('test.state_tables')->hasPendingChanges());
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
        $container = $this->storedApplication(CreateDecisionOrderCommand::class, GetDecisionOrderStatusQuery::class)->boot();
        $mediator = $container->get('test.mediator');
        self::assertNull($mediator->send(new GetDecisionOrderStatusQuery('order')));
        self::assertFalse($container->get('test.state_tables')->hasPendingChanges());
        self::assertEquals(new DecisionOrderState('order', 'pending'), $mediator->send(new CreateDecisionOrderCommand('order')));
        $this->flush($container);
        self::assertSame('pending', $mediator->send(new GetDecisionOrderStatusQuery('order')));
        self::assertFalse($container->get('test.state_tables')->hasPendingChanges());
    }

    public function test_missing_required_stored_states_fail_before_the_decision(): void
    {
        $container = $this->storedApplication(ShipDecisionOrderCommand::class)->boot();
        $this->expectException(StateNotFoundException::class);
        $container->get('test.mediator')->send(new ShipDecisionOrderCommand('missing'));
    }
}
