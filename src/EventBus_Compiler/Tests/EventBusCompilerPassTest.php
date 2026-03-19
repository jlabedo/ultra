<?php

declare(strict_types=1);

namespace Ultra\EventBus_Compiler\Tests;

use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Definition;
use Ultra\EventBus\Attribute\EventHandler;
use Ultra\EventBus\EventBusInterface;
use Ultra\EventBus\EventRegistryInterface;
use Ultra\EventBus\Exception\UnknownEvent;
use Ultra\EventBus_Compiler\EventBusCompilerPass;
use Ultra\Mapper_Bundle\DependencyInjection\Compiler\MapperCompilerPass;
use Ultra\MessageHandling\MessageContext;
use Ultra\MessageHandling\MessageContextKeys;
use Ultra\MessageHandling\MessageContextPropagatorInterface;
use Ultra\MessageHandling\Attribute\After;
use Ultra\MessageHandling\Attribute\AfterReturning;
use Ultra\MessageHandling\Attribute\AfterThrowing;
use Ultra\MessageHandling\Attribute\MatchResult;
use Ultra\Mediator\MediatorInterface;

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

final class RecordingMediator implements MediatorInterface
{
    /** @var list<array{message: object, context: array}> */
    public array $calls = [];

    public function send(object $message, array|MessageContext $context = []): mixed
    {
        $this->calls[] = [
            'message' => $message,
            'context' => MessageContext::coerce($context)->all(),
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

function createEventBusContainer(string $className, array $definitions = [], bool $withMapper = false): ContainerBuilder
{
    $container = new ContainerBuilder();

    if ($withMapper) {
        $container->setParameter('ultra_mapper.normalizable_classes', []);
        $container->addCompilerPass(new MapperCompilerPass(), priority: -100);
    }

    foreach ($definitions as $id => $definition) {
        $container->setDefinition($id, $definition);
    }

    $container->addCompilerPass(new EventBusCompilerPass(TEST_OUTPUT_DIR, $className));
    $container->compile();

    return $container;
}

test('compiler pass registers event bus and registry even with no handlers', function () {
    $container = createEventBusContainer('CompiledEventBusEmpty');

    expect($container->hasDefinition(EventBusInterface::class))->toBeTrue()
        ->and($container->hasDefinition(EventRegistryInterface::class))->toBeTrue();

    $bus = $container->get(EventBusInterface::class);
    $bus->publish(new TestOrderCreated('o-1'));

    $registry = $container->get(EventRegistryInterface::class);
    expect($registry->getClass('event-bus.order-created'))->toBe(TestOrderCreated::class)
        ->and($registry->getName(TestOrderCancelled::class))->toBe('event-bus.order-cancelled');
});

test('event bus routes known events polymorphically in deterministic priority order', function () {
    $log = new EventLog();
    $container = createEventBusContainer('CompiledEventBusRouting', [
        ExactCreatedHandler::class => (new Definition(ExactCreatedHandler::class, [$log]))->addTag('event_bus.handler'),
        BaseEventHandler::class => (new Definition(BaseEventHandler::class, [$log]))->addTag('event_bus.handler'),
        InterfaceEventHandler::class => (new Definition(InterfaceEventHandler::class, [$log]))->addTag('event_bus.handler'),
    ]);

    $container->get(EventBusInterface::class)->publish(new TestOrderCreated('o-2'));

    expect($log->entries)->toBe([
        'exact:o-2',
        'base:o-2',
        'interface:o-2',
    ]);
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
        ThrowingCreatedHandler::class => (new Definition(ThrowingCreatedHandler::class, [$log]))->addTag('event_bus.handler'),
        ExactCreatedHandler::class => (new Definition(ExactCreatedHandler::class, [$log]))->addTag('event_bus.handler'),
        LaterCreatedHandler::class => (new Definition(LaterCreatedHandler::class, [$log]))->addTag('event_bus.handler'),
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
        ExactCreatedHandler::class => (new Definition(ExactCreatedHandler::class, [$log]))->addTag('event_bus.handler'),
        AfterReturningRecorder::class => (new Definition(AfterReturningRecorder::class))->addTag('event_bus.aspect')->setPublic(true),
    ]);

    $container->get(EventBusInterface::class)->publish(new TestOrderCreated('o-6'));

    $recorder = $container->get(AfterReturningRecorder::class);
    expect($recorder->values)->toBe([null]);
});

test('AfterThrowing and After apply to event handlers', function () {
    $log = new EventLog();
    $container = createEventBusContainer('CompiledEventBusAfterFailure', [
        ThrowingCreatedHandler::class => (new Definition(ThrowingCreatedHandler::class, [$log]))->addTag('event_bus.handler'),
        FailureRecorder::class => (new Definition(FailureRecorder::class))->addTag('event_bus.aspect')->setPublic(true),
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
        NestedPublishingCreatedHandler::class => (new Definition(NestedPublishingCreatedHandler::class))->addTag('event_bus.handler'),
        CancelledEventHandler::class => (new Definition(CancelledEventHandler::class, [$log]))->addTag('event_bus.handler'),
        EventContextRecorder::class => (new Definition(EventContextRecorder::class))->addTag('event_bus.aspect')->setPublic(true),
    ]);

    $container->get(EventBusInterface::class)->publish(new TestOrderCreated('o-ctx'), ['traceId' => 'trace-123']);

    $recorder = $container->get(EventContextRecorder::class);

    expect($log->entries)->toBe(['cancelled:o-ctx']);
    assertEventFlowContexts($recorder->entries, TestOrderCreated::class, TestOrderCancelled::class, 'trace-123');
});

test('event bus accepts MessageContext objects at the public publish boundary', function () {
    $log = new EventLog();
    $container = createEventBusContainer('CompiledEventBusMessageContextInput', [
        NestedPublishingCreatedHandler::class => (new Definition(NestedPublishingCreatedHandler::class))->addTag('event_bus.handler'),
        CancelledEventHandler::class => (new Definition(CancelledEventHandler::class, [$log]))->addTag('event_bus.handler'),
        EventContextRecorder::class => (new Definition(EventContextRecorder::class))->addTag('event_bus.aspect')->setPublic(true),
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
    $container->setDefinition(NestedPublishingCreatedHandler::class, (new Definition(NestedPublishingCreatedHandler::class))->addTag('event_bus.handler'));
    $container->setDefinition(CancelledEventHandler::class, (new Definition(CancelledEventHandler::class, [$log]))->addTag('event_bus.handler'));
    $container->setDefinition(EventContextRecorder::class, (new Definition(EventContextRecorder::class))->addTag('event_bus.aspect')->setPublic(true));
    $container->setDefinition(MessageContextPropagatorInterface::class, (new Definition(FixedMessageContextPropagator::class))->setPublic(true));
    $container->addCompilerPass(new EventBusCompilerPass(TEST_OUTPUT_DIR, 'CompiledEventBusCustomPropagator'));
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
    $container->setDefinition(MediatingCreatedHandler::class, (new Definition(MediatingCreatedHandler::class))->addTag('event_bus.handler'));
    $container->setDefinition(RecordingMediator::class, (new Definition(RecordingMediator::class))->setPublic(true));
    $container->setAlias(MediatorInterface::class, RecordingMediator::class)->setPublic(true);
    $container->addCompilerPass(new EventBusCompilerPass(TEST_OUTPUT_DIR, 'CompiledEventBusMediatorContext'));
    $container->compile();

    $container->get(EventBusInterface::class)->publish(new TestOrderCreated('o-med'), ['traceId' => 'trace-med']);

    $mediator = $container->get(RecordingMediator::class);

    expect($mediator->calls)->toHaveCount(1)
        ->and($mediator->calls[0]['message'])->toBeInstanceOf(DispatchFromEventMessage::class)
        ->and($mediator->calls[0]['context']['traceId'])->toBe('trace-med')
        ->and($mediator->calls[0]['context'])->toHaveKey(MessageContextKeys::MESSAGE_ID)
        ->and($mediator->calls[0]['context'])->toHaveKey(MessageContextKeys::CORRELATION_ID);
});

test('event discovery contributes known events to mapper cache warming', function () {
    $container = createEventBusContainer('CompiledEventBusMapper', [], withMapper: true);

    $classes = $container->getParameter('ultra_mapper.normalizable_classes');

    expect($classes)
        ->toContain(TestOrderCreated::class)
        ->toContain(TestOrderCancelled::class);
});

test('compiler pass rejects duplicate event names', function () {
    $tmpDir = dirname(__DIR__).'/Tmp';
    if (! is_dir($tmpDir)) {
        mkdir($tmpDir, 0o777, true);
    }

    // Keep duplicate-event fixtures ephemeral so they do not poison unrelated
    // compiler runs in the rest of the suite.
    $fileA = $tmpDir.'/DuplicateEventA.php';
    $fileB = $tmpDir.'/DuplicateEventB.php';

    file_put_contents($fileA, <<<'PHP'
    <?php

    declare(strict_types=1);

    namespace Ultra\EventBus_Compiler\Tmp;

    use Ultra\MessageHandling\Attribute\Event;

    #[Event('event-bus.duplicate')]
    final readonly class DuplicateEventA
    {
        public function __construct(public string $id) {}
    }
    PHP);

    file_put_contents($fileB, <<<'PHP'
    <?php

    declare(strict_types=1);

    namespace Ultra\EventBus_Compiler\Tmp;

    use Ultra\MessageHandling\Attribute\Event;

    #[Event('event-bus.duplicate')]
    final readonly class DuplicateEventB
    {
        public function __construct(public string $id) {}
    }
    PHP);

    try {
        expect(fn () => createEventBusContainer('CompiledEventBusDuplicate'))
            ->toThrow(\LogicException::class, 'Duplicate #[Event] name');
    } finally {
        @unlink($fileA);
        @unlink($fileB);
    }
});
