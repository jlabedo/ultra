<?php

declare(strict_types=1);

namespace Ultra\StatefulMessageHandling\Tests;

use PHPUnit\Framework\TestCase;
use Ultra\StatefulMessageHandling\Attribute\BindTo;
use Ultra\StatefulMessageHandling\Attribute\DecisionHandler;
use Ultra\StatefulMessageHandling\Attribute\StateReader;
use Ultra\StatefulMessageHandling\DecisionMode;
use Ultra\StatefulMessageHandling\Exception\InvariantViolation;
use Ultra\StatefulMessageHandling\Violation;
use Ultra\StatefulMessageHandling\StatefulMessageHandlingExtension;
use Ultra\EventSourcedState\EventSourcedStateExtension;
use Ultra\EventSourcedState\Tests\Example\Event\CourseCreated;
use Ultra\EventSourcedState\Tests\Example\State\CourseCapacityState;
use Ultra\Events\EventsExtension;
use Ultra\EventStore\DomainIdentifier;
use Ultra\EventStore\EventStore;
use Ultra\EventStore\InMemoryEventStore;
use Ultra\EventStore\StreamQuery;
use Ultra\MappedTable\InMemoryMappedRowSetAdapterFactory;
use Ultra\MappedTable\InMemoryRowDatabase;
use Ultra\MappedTable\MappedRowSetAdapterFactory;
use Ultra\Mapper_Bundle\MapperExtension;
use Ultra\MessageHandling\Attribute\EventHandler;
use Ultra\MessageHandling\Attribute\Handles;
use Ultra\MessageHandling\Attribute\SelfHandler;
use Ultra\MessageHandling\MediatorInterface;
use Ultra\MessageHandling\MessageHandlingExtension;
use Ultra\StateStore\Attribute\State;
use Ultra\StateStore\Attribute\StateColumn;
use Ultra\StateStore\Attribute\StateId;
use Ultra\StateStore\StateStore;
use Ultra\StateStore_Bundle\StateStoreExtension;
use Ultra\Testing\UltraLight;
use Ultra\Testing\UltraLightContainer;

#[State(table: 'decision_orders')]
final readonly class DecisionOrderState
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

final readonly class ShipDecisionOrderCommand
{
    public function __construct(
        public string $orderId,
    ) {}

    #[SelfHandler]
    #[DecisionHandler]
    public function decide(DecisionOrderState $order): DecisionOrderState
    {
        return $order->withStatus('shipped');
    }
}

final readonly class ConfirmDecisionOrderCommand
{
    public function __construct(
        public string $orderId,
    ) {}
}

final readonly class ConfirmDecisionOrderHandler
{
    #[Handles]
    #[DecisionHandler]
    public function handle(
        ConfirmDecisionOrderCommand $command,
        DecisionOrderState $order,
    ): DecisionOrderState {
        return $order->withStatus('confirmed');
    }
}

final readonly class CancelDecisionOrderCommand
{
    public function __construct(
        public string $orderId,
    ) {}

    #[SelfHandler]
    #[DecisionHandler]
    public function decide(DecisionOrderState $order): DecisionOrderState
    {
        if ('shipped' === $order->status) {
            throw new InvariantViolation([new Violation('order.already_shipped', 'Shipped orders cannot be cancelled.')]);
        }

        return $order->withStatus('cancelled');
    }
}

final readonly class CreateDecisionOrderCommand
{
    public function __construct(
        public string $orderId,
    ) {}

    #[SelfHandler]
    #[DecisionHandler]
    public function decide(?DecisionOrderState $order): DecisionOrderState
    {
        if (null !== $order) {
            throw new \LogicException('Order already exists.');
        }

        return new DecisionOrderState($this->orderId, 'pending');
    }
}

final readonly class GetDecisionOrderStatusQuery
{
    public function __construct(
        public string $orderId,
    ) {}

    #[SelfHandler]
    #[StateReader]
    public function handle(?DecisionOrderState $order): ?string
    {
        return $order?->status;
    }
}

final readonly class CreateCourseAndConfirmDecisionOrderCommand
{
    public function __construct(
        public string $courseId,
        public string $orderId,
    ) {}

    #[SelfHandler]
    #[DecisionHandler]
    public function decide(CourseCapacityState $course, DecisionOrderState $order): array
    {
        if ($course->exists()) {
            throw new \LogicException('Course already exists.');
        }

        return [
            new CourseCreated($this->courseId, 'Math', 10),
            $order->withStatus('confirmed'),
        ];
    }
}

final class RecordDecisionCourseCreated
{
    #[EventHandler]
    public function onCourseCreated(CourseCreated $event): void {}
}

final readonly class DecisionAcceptanceId
{
    public function __construct(
        public string $identityId,
        public string $termsId,
    ) {}
}

#[State(table: 'decision_acceptances')]
final readonly class DecisionAcceptanceState
{
    public function __construct(
        #[StateId(columns: ['identityId' => 'identity_id', 'termsId' => 'terms_id'])]
        public DecisionAcceptanceId $acceptanceId,
        public string $status,
    ) {}
}

final readonly class CreateDecisionAcceptanceCommand
{
    public function __construct(
        public DecisionAcceptanceId $acceptanceId,
    ) {}

    #[SelfHandler]
    #[DecisionHandler]
    public function decide(?DecisionAcceptanceState $acceptance): DecisionAcceptanceState
    {
        if (null !== $acceptance) {
            throw new \LogicException('Acceptance already exists.');
        }

        return new DecisionAcceptanceState($this->acceptanceId, 'accepted');
    }
}

#[State(table: 'decision_terms')]
final readonly class DecisionTermsState
{
    public function __construct(
        #[StateId(column: 'terms_id')]
        public string $termsId,
        #[StateColumn('status')]
        public string $status,
    ) {}
}

final readonly class AcceptDecisionTermsCommand
{
    public function __construct(
        public DecisionAcceptanceId $acceptanceId,
    ) {}

    #[SelfHandler]
    #[DecisionHandler]
    public function accept(
        #[BindTo('acceptanceId.termsId')]
        DecisionTermsState $terms,
        ?DecisionAcceptanceState $acceptance,
    ): DecisionAcceptanceState {
        if ('published' !== $terms->status) {
            throw new \LogicException('Terms are not published.');
        }
        if (null !== $acceptance) {
            throw new \LogicException('Acceptance already exists.');
        }

        return new DecisionAcceptanceState($this->acceptanceId, 'accepted');
    }
}

final readonly class GetDecisionTermsStatusQuery
{
    public function __construct(
        public DecisionAcceptanceId $acceptanceId,
    ) {}

    #[SelfHandler]
    #[StateReader]
    public function read(
        #[BindTo('acceptanceId.termsId')]
        DecisionTermsState $terms,
    ): string {
        return $terms->status;
    }
}

/**
 * @internal
 *
 * @coversNothing
 */
final class StateStoreDecisionAspectTest extends TestCase
{
    public function test_bind_to_resolves_nested_property_paths_for_decisions(): void
    {
        $container = $this->ultraLight()->boot();
        $database = $container->get(InMemoryRowDatabase::class);
        $database->seedRow('decision_terms', 'terms_id', [
            'terms_id' => 'terms-2026',
            'status' => 'published',
        ]);
        $id = new DecisionAcceptanceId('identity-1', 'terms-2026');

        $container->get('test.mediator')->send(new AcceptDecisionTermsCommand($id));

        $acceptance = $container->get('test.state_store')->load(DecisionAcceptanceState::class, $id);
        self::assertInstanceOf(DecisionAcceptanceState::class, $acceptance);
        self::assertSame('accepted', $acceptance->status);
    }

    public function test_bind_to_resolves_nested_property_paths_for_state_readers(): void
    {
        $container = $this->ultraLight()->boot();
        $database = $container->get(InMemoryRowDatabase::class);
        $database->seedRow('decision_terms', 'terms_id', [
            'terms_id' => 'terms-2026',
            'status' => 'published',
        ]);

        $status = $container->get('test.mediator')->send(new GetDecisionTermsStatusQuery(
            new DecisionAcceptanceId('identity-1', 'terms-2026'),
        ));

        self::assertSame('published', $status);
    }

    public function test_decision_handler_binds_one_logical_composite_identifier(): void
    {
        $container = $this->ultraLight()->boot();
        $id = new DecisionAcceptanceId('identity-1', 'terms-2026');

        $container->get('test.mediator')->send(new CreateDecisionAcceptanceCommand($id));

        $state = $container->get('test.state_store')->load(DecisionAcceptanceState::class, new DecisionAcceptanceId('identity-1', 'terms-2026'));
        self::assertInstanceOf(DecisionAcceptanceState::class, $state);
        self::assertSame('accepted', $state->status);
    }

    public function test_it_injects_loaded_state_and_saves_returned_state(): void
    {
        $container = $this->ultraLight()->boot();
        $database = $container->get(InMemoryRowDatabase::class);
        $database->seedRow('decision_orders', 'order_id', [
            'order_id' => 'order-1',
            'status' => 'paid',
        ]);

        $result = $container->get('test.mediator')->send(new ShipDecisionOrderCommand('order-1'));

        self::assertEquals(new DecisionOrderState('order-1', 'shipped'), $result);
        $state = $container->get('test.state_store')->load(DecisionOrderState::class, 'order-1');
        self::assertInstanceOf(DecisionOrderState::class, $state);
        self::assertSame('shipped', $state->status);
    }

    public function test_it_supports_a_decision_handler_on_a_separate_handler(): void
    {
        $container = $this->ultraLight()->boot();
        $database = $container->get(InMemoryRowDatabase::class);
        $database->seedRow('decision_orders', 'order_id', [
            'order_id' => 'order-5',
            'status' => 'paid',
        ]);

        $result = $container->get('test.mediator')->send(new ConfirmDecisionOrderCommand('order-5'));

        self::assertEquals(new DecisionOrderState('order-5', 'confirmed'), $result);
        $state = $container->get('test.state_store')->load(DecisionOrderState::class, 'order-5');
        self::assertInstanceOf(DecisionOrderState::class, $state);
        self::assertSame('confirmed', $state->status);
    }

    public function test_check_mode_returns_violations_without_saving(): void
    {
        $container = $this->ultraLight()->boot();
        $container->get(InMemoryRowDatabase::class)->seedRow('decision_orders', 'order_id', [
            'order_id' => 'order-6',
            'status' => 'shipped',
        ]);

        $violations = $container->get('test.mediator')->send(
            new CancelDecisionOrderCommand('order-6'),
            [DecisionMode::CONTEXT_KEY => DecisionMode::CHECK],
        );

        self::assertEquals([new Violation('order.already_shipped', 'Shipped orders cannot be cancelled.')], $violations);
        self::assertSame('shipped', $container->get('test.state_store')->load(DecisionOrderState::class, 'order-6')?->status);
    }

    public function test_check_mode_returns_no_violations_and_discards_returned_states(): void
    {
        $container = $this->ultraLight()->boot();
        $container->get(InMemoryRowDatabase::class)->seedRow('decision_orders', 'order_id', [
            'order_id' => 'order-7',
            'status' => 'paid',
        ]);

        $violations = $container->get('test.mediator')->send(
            new CancelDecisionOrderCommand('order-7'),
            [DecisionMode::CONTEXT_KEY => DecisionMode::CHECK],
        );

        self::assertSame([], $violations);
        self::assertSame('paid', $container->get('test.state_store')->load(DecisionOrderState::class, 'order-7')?->status);
    }

    public function test_nullable_state_parameter_allows_missing_state_and_saves_returned_state(): void
    {
        $container = $this->ultraLight()->boot();

        $result = $container->get('test.mediator')->send(new CreateDecisionOrderCommand('order-2'));

        self::assertEquals(new DecisionOrderState('order-2', 'pending'), $result);
        $state = $container->get('test.state_store')->load(DecisionOrderState::class, 'order-2');
        self::assertInstanceOf(DecisionOrderState::class, $state);
        self::assertSame('pending', $state->status);
    }

    public function test_it_injects_state_into_a_query_without_preparing_a_write(): void
    {
        $container = $this->ultraLight()->boot();
        $database = $container->get(InMemoryRowDatabase::class);
        $database->seedRow('decision_orders', 'order_id', [
            'order_id' => 'order-4',
            'status' => 'paid',
        ]);

        $result = $container->get('test.mediator')->send(new GetDecisionOrderStatusQuery('order-4'));

        self::assertSame('paid', $result);
        self::assertSame([
            'status' => 'paid',
        ], $database->row('decision_orders', 'order_id', 'order-4', ['status']));
    }

    public function test_event_sourced_and_state_store_aspects_can_share_one_handler(): void
    {
        $container = $this->ultraLight()->boot();
        $database = $container->get(InMemoryRowDatabase::class);
        $database->seedRow('decision_orders', 'order_id', [
            'order_id' => 'order-3',
            'status' => 'paid',
        ]);

        $result = $container->get('test.mediator')->send(new CreateCourseAndConfirmDecisionOrderCommand('course-1', 'order-3'));

        self::assertEquals([
            new CourseCreated('course-1', 'Math', 10),
            new DecisionOrderState('order-3', 'confirmed'),
        ], $result);

        $events = $container->get(InMemoryEventStore::class)
            ->load(StreamQuery::forStream(new DomainIdentifier('course', 'course-1')))
            ->events;
        self::assertCount(1, $events);
        self::assertSame('course.created', $events[0]->eventType);

        $state = $container->get('test.state_store')->load(DecisionOrderState::class, 'order-3');
        self::assertInstanceOf(DecisionOrderState::class, $state);
        self::assertSame('confirmed', $state->status);
    }

    /**
     * @return UltraLight<UltraLightContainer>
     */
    private function ultraLight(): UltraLight
    {
        $database = new InMemoryRowDatabase();

        return (new UltraLight())
            ->withBuildDir(sys_get_temp_dir().'/ultra_state_store_decision_aspect_tests_'.uniqid())
            ->withBundle(
                new MessageHandlingExtension(),
                new MapperExtension(),
                new EventsExtension(),
                new EventSourcedStateExtension(),
                new StateStoreExtension(),
                new StatefulMessageHandlingExtension(),
            )
            ->withService(new InMemoryEventStore(), InMemoryEventStore::class)
            ->withAlias(EventStore::class, InMemoryEventStore::class)
            ->withService($database, InMemoryRowDatabase::class)
            ->withService(new InMemoryMappedRowSetAdapterFactory($database), InMemoryMappedRowSetAdapterFactory::class)
            ->withAlias(MappedRowSetAdapterFactory::class, InMemoryMappedRowSetAdapterFactory::class)
            ->withAlias('test.mediator', MediatorInterface::class)
            ->withAlias('test.state_store', StateStore::class)
            ->withClass(
                DecisionOrderState::class,
                CourseCapacityState::class,
                CourseCreated::class,
                ShipDecisionOrderCommand::class,
                ConfirmDecisionOrderCommand::class,
                CancelDecisionOrderCommand::class,
                ConfirmDecisionOrderHandler::class,
                CreateDecisionOrderCommand::class,
                GetDecisionOrderStatusQuery::class,
                CreateCourseAndConfirmDecisionOrderCommand::class,
                RecordDecisionCourseCreated::class,
                DecisionAcceptanceId::class,
                DecisionAcceptanceState::class,
                CreateDecisionAcceptanceCommand::class,
                DecisionTermsState::class,
                AcceptDecisionTermsCommand::class,
                GetDecisionTermsStatusQuery::class,
            )
        ;
    }
}
