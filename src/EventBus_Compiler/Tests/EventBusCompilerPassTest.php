<?php

declare(strict_types=1);

namespace Ultra\EventBus_Compiler\Tests;

use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Definition;
use Ultra\EventBus\Attribute\EventHandler;
use Ultra\EventBus\EventBusInterface;
use Ultra\EventBus\Exception\UnknownEvent;
use Ultra\Events\EventsBundle;
use Ultra\Mediator\MediatorInterface;
use Ultra\MessageHandling\Attribute\After;
use Ultra\MessageHandling\Attribute\AfterReturning;
use Ultra\MessageHandling\Attribute\AfterThrowing;
use Ultra\MessageHandling\Attribute\MatchResult;
use Ultra\MessageHandling\ContextAwareRouterInterface;
use Ultra\MessageHandling\MessageContext;
use Ultra\MessageHandling\MessageContextKeys;
use Ultra\MessageHandling\MessageContextPropagatorInterface;
use Ultra\MessageHandling_Compiler\MessageRouterCompilerPass;

const TEST_OUTPUT_DIR = __DIR__.'/../../../var/event_bus_compiler_tests';

/**
 * @param list<array{message: string, context: array}> $entries
 */
function assertEventFlowContexts(array $entries, string $rootEvent, string $nestedEvent, string $traceId): void
{
    expect($entries)->toHaveCount(2)
        ->and($entries[0]['message'])->toBe($nestedEvent)
        ->and($entries[1]['message'])->toBe($rootEvent)
        ->and($entries[0]['context']['traceId'])->toBe($traceId)
        ->and($entries[1]['context']['traceId'])->toBe($traceId)
        ->and($entries[0]['context'])->toHaveKey(MessageContextKeys::MESSAGE_ID)
        ->and($entries[0]['context'])->toHaveKey(MessageContextKeys::CORRELATION_ID)
        ->and($entries[0]['context'])->toHaveKey(MessageContextKeys::CAUSATION_ID)
        ->and($entries[1]['context'])->toHaveKey(MessageContextKeys::MESSAGE_ID)
        ->and($entries[1]['context'])->toHaveKey(MessageContextKeys::CORRELATION_ID)
        ->and($entries[1]['context'][MessageContextKeys::CORRELATION_ID])->toBe($entries[1]['context'][MessageContextKeys::MESSAGE_ID])
        ->and($entries[0]['context'][MessageContextKeys::CORRELATION_ID])->toBe($entries[1]['context'][MessageContextKeys::CORRELATION_ID])
        ->and($entries[0]['context'][MessageContextKeys::CAUSATION_ID])->toBe($entries[1]['context'][MessageContextKeys::MESSAGE_ID]);
}

interface TestDomainEvent {}

abstract readonly class AbstractTestOrderEvent implements TestDomainEvent
{
    public function __construct(public string $orderId) {}
}

final readonly class UnknownTestEvent
{
    public function __construct(public string $orderId) {}
}

final readonly class DispatchFromEventMessage
{
    public function __construct(public string $orderId) {}
}

final class EventLog
{
    /** @var list<string> */
    public array $entries = [];
}

final class RecordingMediator implements MediatorInterface, ContextAwareRouterInterface
{
    /** @var list<array{message: object, context: array}> */
    public array $calls = [];

    private ?MessageContext $parentContext = null;

    public function withContext(MessageContext $context): static
    {
        $this->parentContext = $context;

        return $this;
    }

    public function send(object $message, array|MessageContext $context = []): mixed
    {
        $coerced = MessageContext::coerce($context);
        $merged = null !== $this->parentContext ? $this->parentContext->merge($coerced) : $coerced;
        $this->calls[] = [
            'message' => $message,
            'context' => $merged->all(),
        ];

        return 'sent';
    }
}

final class FixedMessageContextPropagator implements MessageContextPropagatorInterface
{
    public function createDispatchContext(MessageContext $context): MessageContext
    {
        if ($context->has(MessageContextKeys::MESSAGE_ID) && ! $context->has(MessageContextKeys::CAUSATION_ID)) {
            return $context
                ->with(MessageContextKeys::MESSAGE_ID, 'fixed-child')
                ->with(MessageContextKeys::CORRELATION_ID, 'fixed-correlation')
                ->with(MessageContextKeys::CAUSATION_ID, 'fixed-root');
        }

        return $context
            ->with(MessageContextKeys::MESSAGE_ID, 'fixed-root')
            ->with(MessageContextKeys::CORRELATION_ID, 'fixed-correlation');
    }
}

final class ExactCreatedHandler
{
    public function __construct(private readonly EventLog $log) {}

    #[EventHandler(priority: 30)]
    public function handle(TestOrderCreated $event): void
    {
        $this->log->entries[] = 'exact:'.$event->orderId;
    }
}

final class BaseEventHandler
{
    public function __construct(private readonly EventLog $log) {}

    #[EventHandler(priority: 20)]
    public function handle(AbstractTestOrderEvent $event): void
    {
        $this->log->entries[] = 'base:'.$event->orderId;
    }
}

final class InterfaceEventHandler
{
    public function __construct(private readonly EventLog $log) {}

    #[EventHandler(priority: 10)]
    public function handle(TestDomainEvent $event): void
    {
        $this->log->entries[] = 'interface:'.$event->orderId;
    }
}

final class ThrowingCreatedHandler
{
    public function __construct(private readonly EventLog $log) {}

    #[EventHandler(priority: 40)]
    public function handle(TestOrderCreated $event): void
    {
        $this->log->entries[] = 'throwing:'.$event->orderId;

        throw new \RuntimeException('boom');
    }
}

final class LaterCreatedHandler
{
    public function __construct(private readonly EventLog $log) {}

    #[EventHandler(priority: 5)]
    public function handle(TestOrderCreated $event): void
    {
        $this->log->entries[] = 'later:'.$event->orderId;
    }
}

final class NestedPublishingCreatedHandler
{
    #[EventHandler(priority: 50)]
    public function handle(TestOrderCreated $event, EventBusInterface $eventBus): void
    {
        $eventBus->publish(new TestOrderCancelled($event->orderId));
    }
}

final class CancelledEventHandler
{
    public function __construct(private readonly EventLog $log) {}

    #[EventHandler]
    public function handle(TestOrderCancelled $event): void
    {
        $this->log->entries[] = 'cancelled:'.$event->orderId;
    }
}

final class MediatingCreatedHandler
{
    #[EventHandler(priority: 50)]
    public function handle(TestOrderCreated $event, MediatorInterface $mediator): void
    {
        $mediator->send(new DispatchFromEventMessage($event->orderId));
    }
}

final class AfterReturningRecorder
{
    /** @var list<mixed> */
    public array $values = [];

    #[AfterReturning(static function (\ReflectionMethod $method): ?MatchResult {
        return [] !== $method->getAttributes(EventHandler::class)
            ? MatchResult::apply()
            : null;
    })]
    public function record(object $message, MessageContext $context, mixed $returnValue): void
    {
        $this->values[] = $returnValue;
    }
}

final class FailureRecorder
{
    public ?string $message = null;
    public int $afterCalls = 0;

    #[AfterThrowing(static function (\ReflectionMethod $method): ?MatchResult {
        return [] !== $method->getAttributes(EventHandler::class)
            ? MatchResult::apply()
            : null;
    })]
    public function record(object $message, MessageContext $context, \Throwable $error): void
    {
        $this->message = $error->getMessage();
    }

    #[After(static function (\ReflectionMethod $method): ?MatchResult {
        return [] !== $method->getAttributes(EventHandler::class)
            ? MatchResult::apply()
            : null;
    })]
    public function after(object $message, MessageContext $context): void
    {
        ++$this->afterCalls;
    }
}

final class EventContextRecorder
{
    /** @var list<array{message: string, context: array}> */
    public array $entries = [];

    #[After(static function (\ReflectionMethod $method): ?MatchResult {
        return [] !== $method->getAttributes(EventHandler::class)
            ? MatchResult::apply()
            : null;
    })]
    public function after(object $message, MessageContext $context): void
    {
        $this->entries[] = [
            'message' => $message::class,
            'context' => $context->all(),
        ];
    }
}

function createEventBusContainer(string $className, array $definitions = []): ContainerBuilder
{
    $container = new ContainerBuilder();
    new EventsBundle()->build($container);

    $container->addDefinitions($definitions);
    $container->addDefinitions([
        TestOrderCreated::class => createEventDefinition(TestOrderCreated::class),
        TestOrderCancelled::class => createEventDefinition(TestOrderCancelled::class),
    ]);

    registerEventBusRouter($container);
    $container->addCompilerPass(new MessageRouterCompilerPass(
        TEST_OUTPUT_DIR,
        classNameOverrides: [EventBusInterface::class => $className],
    ));
    $container->compile();

    return $container;
}

function registerEventBusRouter(ContainerBuilder $container): void
{
    $container->setDefinition(
        EventBusInterface::class,
        (new Definition(EventBusInterface::class))
            ->addTag('ultra.message_router', ['interface' => EventBusInterface::class])
            ->setAbstract(true),
    );
}

function createEventDefinition(string $class): Definition
{
    return (new Definition($class))
        ->addResourceTag('ultra_event_bus.event');
}

test('compiler pass registers event bus even with no handlers', function () {
    $container = createEventBusContainer('CompiledEventBusEmpty');

    expect($container->hasDefinition(EventBusInterface::class))->toBeTrue();

    $bus = $container->get(EventBusInterface::class);
    $bus->publish(new TestOrderCreated('o-1'));
});

test('event bus routes known events polymorphically in deterministic priority order', function () {
    $log = new EventLog();
    $container = createEventBusContainer('CompiledEventBusRouting', [
        ExactCreatedHandler::class => (new Definition(ExactCreatedHandler::class, [$log]))->addTag('ultra.message_handler'),
        BaseEventHandler::class => (new Definition(BaseEventHandler::class, [$log]))->addTag('ultra.message_handler'),
        InterfaceEventHandler::class => (new Definition(InterfaceEventHandler::class, [$log]))->addTag('ultra.message_handler'),
    ]);

    $container->get(EventBusInterface::class)->publish(new TestOrderCreated('o-2'));

    expect($log->entries)->toBe([
        'exact:o-2',
        'base:o-2',
        'interface:o-2',
    ]);
});

test('event bus uses a constant route table and loops over ordered handler methods', function () {
    $log = new EventLog();
    $container = createEventBusContainer('CompiledEventBusRouteTable', [
        ExactCreatedHandler::class => (new Definition(ExactCreatedHandler::class, [$log]))->addTag('ultra.message_handler'),
        LaterCreatedHandler::class => (new Definition(LaterCreatedHandler::class, [$log]))->addTag('ultra.message_handler'),
    ]);

    $code = file_get_contents(TEST_OUTPUT_DIR.'/EventBusInterface/CompiledEventBusRouteTable.php');

    expect($container->get(EventBusInterface::class))->toBeInstanceOf(EventBusInterface::class)
        ->and($code)->toBeString()
        ->and($code)->toContain('private const ROUTES = [')
        ->and($code)->toContain('\\'.TestOrderCreated::class.'::class => [')
        ->and($code)->toContain("'invokeTestOrderCreated_0_")
        ->and($code)->toContain("'invokeTestOrderCreated_1_")
        ->and($code)->toContain('$methods = self::ROUTES[$event::class] ?? null;')
        ->and($code)->toContain('foreach ($methods as $m) {')
        ->and($code)->toContain('$this->{$m}($event, $messageContext);')
        ->and($code)->not->toContain('match ($event::class)')
        ->and($code)->not->toContain('private function publishTestOrderCreated_');
});

test('event bus treats known events with zero handlers as a no-op', function () {
    $container = createEventBusContainer('CompiledEventBusNoop');

    $container->get(EventBusInterface::class)->publish(new TestOrderCancelled('o-3'));

    expect(true)->toBeTrue();
});

test('event bus throws for unknown unannotated events', function () {
    $container = createEventBusContainer('CompiledEventBusUnknown');

    expect(fn () => $container->get(EventBusInterface::class)->publish(new UnknownTestEvent('o-4')))
        ->toThrow(UnknownEvent::class);
});

test('event bus stops on first failing handler and rethrows the exception', function () {
    $log = new EventLog();
    $container = createEventBusContainer('CompiledEventBusFailure', [
        ThrowingCreatedHandler::class => (new Definition(ThrowingCreatedHandler::class, [$log]))->addTag('ultra.message_handler'),
        ExactCreatedHandler::class => (new Definition(ExactCreatedHandler::class, [$log]))->addTag('ultra.message_handler'),
        LaterCreatedHandler::class => (new Definition(LaterCreatedHandler::class, [$log]))->addTag('ultra.message_handler'),
    ]);

    expect(fn () => $container->get(EventBusInterface::class)->publish(new TestOrderCreated('o-5')))
        ->toThrow(\RuntimeException::class, 'boom');

    expect($log->entries)->toBe([
        'throwing:o-5',
    ]);
});

test('AfterReturning receives null for successful void event handlers', function () {
    $log = new EventLog();
    $container = createEventBusContainer('CompiledEventBusAfterReturning', [
        ExactCreatedHandler::class => (new Definition(ExactCreatedHandler::class, [$log]))->addTag('ultra.message_handler'),
        AfterReturningRecorder::class => (new Definition(AfterReturningRecorder::class))->addTag('ultra.message_aspect')->setPublic(true),
    ]);

    $container->get(EventBusInterface::class)->publish(new TestOrderCreated('o-6'));

    $recorder = $container->get(AfterReturningRecorder::class);
    expect($recorder->values)->toBe([null]);
});

test('AfterThrowing and After apply to event handlers', function () {
    $log = new EventLog();
    $container = createEventBusContainer('CompiledEventBusAfterFailure', [
        ThrowingCreatedHandler::class => (new Definition(ThrowingCreatedHandler::class, [$log]))->addTag('ultra.message_handler'),
        FailureRecorder::class => (new Definition(FailureRecorder::class))->addTag('ultra.message_aspect')->setPublic(true),
    ]);

    expect(fn () => $container->get(EventBusInterface::class)->publish(new TestOrderCreated('o-7')))
        ->toThrow(\RuntimeException::class, 'boom');

    $recorder = $container->get(FailureRecorder::class);
    expect($recorder->message)->toBe('boom')
        ->and($recorder->afterCalls)->toBe(1);
});

test('event bus propagates message context through nested publish calls from handlers', function () {
    $log = new EventLog();
    $container = createEventBusContainer('CompiledEventBusNestedPublishContext', [
        NestedPublishingCreatedHandler::class => (new Definition(NestedPublishingCreatedHandler::class))->addTag('ultra.message_handler'),
        CancelledEventHandler::class => (new Definition(CancelledEventHandler::class, [$log]))->addTag('ultra.message_handler'),
        EventContextRecorder::class => (new Definition(EventContextRecorder::class))->addTag('ultra.message_aspect')->setPublic(true),
    ]);

    $container->get(EventBusInterface::class)->publish(new TestOrderCreated('o-ctx'), ['traceId' => 'trace-123']);

    $recorder = $container->get(EventContextRecorder::class);

    expect($log->entries)->toBe(['cancelled:o-ctx']);
    assertEventFlowContexts($recorder->entries, TestOrderCreated::class, TestOrderCancelled::class, 'trace-123');
});

test('event bus accepts MessageContext objects at the public publish boundary', function () {
    $log = new EventLog();
    $container = createEventBusContainer('CompiledEventBusMessageContextInput', [
        NestedPublishingCreatedHandler::class => (new Definition(NestedPublishingCreatedHandler::class))->addTag('ultra.message_handler'),
        CancelledEventHandler::class => (new Definition(CancelledEventHandler::class, [$log]))->addTag('ultra.message_handler'),
        EventContextRecorder::class => (new Definition(EventContextRecorder::class))->addTag('ultra.message_aspect')->setPublic(true),
    ]);

    $container->get(EventBusInterface::class)->publish(
        new TestOrderCreated('o-typed'),
        MessageContext::coerce(['traceId' => 'trace-typed']),
    );

    $recorder = $container->get(EventContextRecorder::class);

    expect($log->entries)->toBe(['cancelled:o-typed']);
    assertEventFlowContexts($recorder->entries, TestOrderCreated::class, TestOrderCancelled::class, 'trace-typed');
});

test('event bus uses the configured message context propagator service', function () {
    $log = new EventLog();
    $container = new ContainerBuilder();
    new EventsBundle()->build($container);
    $container->setDefinition(TestOrderCreated::class, createEventDefinition(TestOrderCreated::class));
    $container->setDefinition(TestOrderCancelled::class, createEventDefinition(TestOrderCancelled::class));
    $container->setDefinition(NestedPublishingCreatedHandler::class, (new Definition(NestedPublishingCreatedHandler::class))->addTag('ultra.message_handler'));
    $container->setDefinition(CancelledEventHandler::class, (new Definition(CancelledEventHandler::class, [$log]))->addTag('ultra.message_handler'));
    $container->setDefinition(EventContextRecorder::class, (new Definition(EventContextRecorder::class))->addTag('ultra.message_aspect')->setPublic(true));
    $container->setDefinition(MessageContextPropagatorInterface::class, (new Definition(FixedMessageContextPropagator::class))->setPublic(true));
    registerEventBusRouter($container);
    $container->addCompilerPass(new MessageRouterCompilerPass(
        TEST_OUTPUT_DIR,
        classNameOverrides: [EventBusInterface::class => 'CompiledEventBusCustomPropagator'],
    ));
    $container->compile();

    $container->get(EventBusInterface::class)->publish(new TestOrderCreated('o-custom'), ['traceId' => 'trace-custom']);

    $recorder = $container->get(EventContextRecorder::class);

    expect($log->entries)->toBe(['cancelled:o-custom'])
        ->and($recorder->entries[1]['context'][MessageContextKeys::MESSAGE_ID])->toBe('fixed-root')
        ->and($recorder->entries[1]['context'][MessageContextKeys::CORRELATION_ID])->toBe('fixed-correlation')
        ->and($recorder->entries[0]['context'][MessageContextKeys::MESSAGE_ID])->toBe('fixed-child')
        ->and($recorder->entries[0]['context'][MessageContextKeys::CORRELATION_ID])->toBe('fixed-correlation')
        ->and($recorder->entries[0]['context'][MessageContextKeys::CAUSATION_ID])->toBe('fixed-root');
});

test('event bus propagates message context to mediator injected into handlers', function () {
    $container = new ContainerBuilder();
    new EventsBundle()->build($container);
    $container->setDefinition(TestOrderCreated::class, createEventDefinition(TestOrderCreated::class));
    $container->setDefinition(MediatingCreatedHandler::class, (new Definition(MediatingCreatedHandler::class))->addTag('ultra.message_handler'));
    $container->setDefinition(RecordingMediator::class, (new Definition(RecordingMediator::class))->setPublic(true));
    $container->setAlias(MediatorInterface::class, RecordingMediator::class)->setPublic(true);
    registerEventBusRouter($container);
    $container->addCompilerPass(new MessageRouterCompilerPass(
        TEST_OUTPUT_DIR,
        classNameOverrides: [EventBusInterface::class => 'CompiledEventBusMediatorContext'],
    ));
    $container->compile();

    $container->get(EventBusInterface::class)->publish(new TestOrderCreated('o-med'), ['traceId' => 'trace-med']);

    $mediator = $container->get(RecordingMediator::class);

    expect($mediator->calls)->toHaveCount(1)
        ->and($mediator->calls[0]['message'])->toBeInstanceOf(DispatchFromEventMessage::class)
        ->and($mediator->calls[0]['context']['traceId'])->toBe('trace-med')
        ->and($mediator->calls[0]['context'])->toHaveKey(MessageContextKeys::MESSAGE_ID)
        ->and($mediator->calls[0]['context'])->toHaveKey(MessageContextKeys::CORRELATION_ID);
});
