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
use Ultra\MessageHandling\Attribute\After;
use Ultra\MessageHandling\Attribute\AfterReturning;
use Ultra\MessageHandling\Attribute\AfterThrowing;
use Ultra\MessageHandling\Attribute\MatchResult;

const TEST_OUTPUT_DIR = __DIR__.'/../../../var/event_bus_compiler_tests';

interface TestDomainEvent {}

abstract readonly class AbstractTestOrderEvent implements TestDomainEvent
{
    public function __construct(public string $orderId) {}
}

final readonly class UnknownTestEvent
{
    public function __construct(public string $orderId) {}
}

final class EventLog
{
    /** @var list<string> */
    public array $entries = [];
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

final class AfterReturningRecorder
{
    /** @var list<mixed> */
    public array $values = [];

    #[AfterReturning(static function (\ReflectionMethod $method): ?MatchResult {
        return [] !== $method->getAttributes(EventHandler::class)
            ? MatchResult::apply()
            : null;
    })]
    public function record(object $message, array $context, mixed $returnValue): void
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
    public function record(object $message, array $context, \Throwable $error): void
    {
        $this->message = $error->getMessage();
    }

    #[After(static function (\ReflectionMethod $method): ?MatchResult {
        return [] !== $method->getAttributes(EventHandler::class)
            ? MatchResult::apply()
            : null;
    })]
    public function after(object $message, array $context): void
    {
        ++$this->afterCalls;
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
