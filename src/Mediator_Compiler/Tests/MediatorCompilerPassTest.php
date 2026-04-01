<?php

declare(strict_types=1);

namespace Ultra\Mediator_Compiler\Tests;

use Psr\Container\ContainerInterface;
use Symfony\Component\DependencyInjection\Argument\ServiceLocatorArgument;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Definition;
use Symfony\Component\DependencyInjection\Reference;
use Ultra\MessageHandling\MessageContext;
use Ultra\MessageHandling\MessageContextKeys;
use Ultra\MessageHandling\MessageContextPropagatorInterface;
use Ultra\MessageHandling\Attribute\After;
use Ultra\MessageHandling\Attribute\AfterReturning;
use Ultra\MessageHandling\Attribute\AfterThrowing;
use Ultra\MessageHandling\Attribute\Around;
use Ultra\MessageHandling\Attribute\Before;
use Ultra\EventBus\EventBusInterface;
use Ultra\Mediator\Attribute\Handles;
use Ultra\MessageHandling\Attribute\MatchResult;
use Ultra\Mediator\Exception\NoHandlerFound;
use Ultra\Mediator\MediatorInterface;
use Ultra\Mediator_Compiler\MediatorCompilerPass;

// ---------------------------------------------------------------------------
// Shared temp directory for this test run
// ---------------------------------------------------------------------------

$testOutputDir = dirname(__DIR__, 3).'/var/mediator_compiler_tests';

/**
 * @param list<array{message: string, context: array}> $entries
 */
function assertMessageFlowContexts(array $entries, string $firstMessage, string $secondMessage, string $traceId): void
{
    expect($entries)->toHaveCount(2)
        ->and($entries[0]['message'])->toBe($firstMessage)
        ->and($entries[1]['message'])->toBe($secondMessage)
        ->and($entries[0]['context']['traceId'])->toBe($traceId)
        ->and($entries[1]['context']['traceId'])->toBe($traceId)
        ->and($entries[0]['context'])->toHaveKey(MessageContextKeys::MESSAGE_ID)
        ->and($entries[0]['context'])->toHaveKey(MessageContextKeys::CORRELATION_ID)
        ->and($entries[1]['context'])->toHaveKey(MessageContextKeys::MESSAGE_ID)
        ->and($entries[1]['context'])->toHaveKey(MessageContextKeys::CORRELATION_ID)
        ->and($entries[1]['context'])->toHaveKey(MessageContextKeys::CAUSATION_ID)
        ->and($entries[0]['context'][MessageContextKeys::CORRELATION_ID])->toBe($entries[0]['context'][MessageContextKeys::MESSAGE_ID])
        ->and($entries[1]['context'][MessageContextKeys::CORRELATION_ID])->toBe($entries[0]['context'][MessageContextKeys::CORRELATION_ID])
        ->and($entries[1]['context'][MessageContextKeys::CAUSATION_ID])->toBe($entries[0]['context'][MessageContextKeys::MESSAGE_ID]);
}

function compileMediatorTestContainer(ContainerBuilder $container, string $testOutputDir, string $compiledClassName): void
{
    $container->setAlias('test.mediator', MediatorInterface::class)->setPublic(true);
    $container->addCompilerPass(new MediatorCompilerPass($testOutputDir, $compiledClassName));
    $container->compile();
}

function getTestMediator(ContainerBuilder $container): MediatorInterface
{
    /** @var MediatorInterface $mediator */
    $mediator = $container->get('test.mediator');

    return $mediator;
}

// ---------------------------------------------------------------------------
// Message fixtures
// ---------------------------------------------------------------------------

final readonly class HelloMessage
{
    public function __construct(public string $name) {}
}

final readonly class GoodbyeMessage
{
    public function __construct(public string $name) {}
}

final readonly class PingMessage {}

final readonly class AuditedMessage
{
    public function __construct(public string $payload) {}
}

final readonly class WrappedMessage
{
    public function __construct(public string $payload) {}
}

final readonly class DoubleWrappedMessage
{
    public function __construct(public string $payload) {}
}

final readonly class ParentMessage
{
    public function __construct(public string $payload) {}
}

final readonly class ChildMessage
{
    public function __construct(public string $payload) {}
}

final readonly class PublishedFromMediatorMessage
{
    public function __construct(public string $payload) {}
}

final readonly class RecordedEvent
{
    public function __construct(public string $payload) {}
}

// ---------------------------------------------------------------------------
// Service fixtures (plain collaborators)
// ---------------------------------------------------------------------------

final class GreeterService
{
    public function greet(string $name): string
    {
        return 'Hello, '.$name.'!';
    }
}

final class RecordingEventBus implements EventBusInterface
{
    /** @var list<array{event: object, context: array}> */
    public array $calls = [];

    public function publish(object $event, array|MessageContext $context = []): void
    {
        $this->calls[] = [
            'event' => $event,
            'context' => MessageContext::coerce($context)->all(),
        ];
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

// ---------------------------------------------------------------------------
// Aspect service fixtures
//
// Each aspect method is decorated with #[Before] or #[Around].
// The static closure receives the handler's ReflectionMethod and returns:
//   null                  → this aspect does not apply to that handler
//   MatchResult::apply()  → apply; optional 'config' and 'provides' (claimed dep slots)
// ---------------------------------------------------------------------------

final class TestLogger
{
    /** @var list<object> */
    public array $logged = [];

    #[Before(static function (\ReflectionMethod $m): ?MatchResult {
        return MatchResult::apply(); // apply to every handler registered alongside this aspect
    })]
    public function log(object $message, MessageContext $context): void
    {
        $this->logged[] = $message;
    }
}

final class MessageContextRecorder
{
    /** @var list<array{message: string, context: array}> */
    public array $entries = [];

    #[Before(static function (\ReflectionMethod $m): ?MatchResult {
        return MatchResult::apply();
    })]
    public function record(object $message, MessageContext $context): void
    {
        $this->entries[] = [
            'message' => $message::class,
            'context' => $context->all(),
        ];
    }
}

final class TestTransactionManager
{
    public int $calls = 0;

    #[Around(static function (\ReflectionMethod $m): ?MatchResult {
        return MatchResult::apply(); // apply to every handler registered alongside this aspect
    })]
    public function execute(callable $proceed, object $message, MessageContext $context): mixed
    {
        ++$this->calls;

        return $proceed();
    }
}

final class OuterAspectService
{
    /** @var list<string> */
    public array $log = [];

    #[Around(static function (\ReflectionMethod $m): ?MatchResult {
        return MatchResult::apply();
    }, priority: 0)]
    public function wrap(callable $proceed, object $message, MessageContext $context): mixed
    {
        $this->log[] = 'outer-before';
        $result = $proceed();
        $this->log[] = 'outer-after';

        return $result;
    }
}

final class InnerAspectService
{
    /** @var list<string> */
    public array $log = [];

    #[Around(static function (\ReflectionMethod $m): ?MatchResult {
        return MatchResult::apply();
    }, priority: 10)]
    public function wrap(callable $proceed, object $message, MessageContext $context): mixed
    {
        $this->log[] = 'inner-before';
        $result = $proceed();
        $this->log[] = 'inner-after';

        return $result;
    }
}

// ---------------------------------------------------------------------------
// Handler fixtures (plain — no aspect attributes; aspects are declared
// on the aspect service side and matched via their $matcher closures)
// ---------------------------------------------------------------------------

final class SalutationHandler
{
    #[Handles]
    public function hello(HelloMessage $msg): string
    {
        return 'Hi, '.$msg->name;
    }

    #[Handles]
    public function goodbye(GoodbyeMessage $msg): string
    {
        return 'Bye, '.$msg->name;
    }

    /** Regular method — must be ignored */
    public function helper(): void {}
}

final class GreetWithServiceHandler
{
    #[Handles]
    public function ping(PingMessage $_msg, GreeterService $greeter): string
    {
        return $greeter->greet('pong');
    }
}

final class AuditedHandler
{
    #[Handles]
    public function handle(AuditedMessage $msg): string
    {
        return 'handled: '.$msg->payload;
    }
}

final class WrappedHandler
{
    #[Handles]
    public function handle(WrappedMessage $msg): string
    {
        return 'wrapped: '.$msg->payload;
    }
}

final class DoubleWrappedHandler
{
    #[Handles]
    public function handle(DoubleWrappedMessage $msg): string
    {
        return 'double: '.$msg->payload;
    }
}

final class ParentHandler
{
    #[Handles]
    public function handle(ParentMessage $msg, MediatorInterface $mediator): string
    {
        return $mediator->send(new ChildMessage($msg->payload));
    }
}

final class ChildHandler
{
    #[Handles]
    public function handle(ChildMessage $msg): string
    {
        return 'child: '.$msg->payload;
    }
}

final class EventPublishingHandler
{
    #[Handles]
    public function handle(PublishedFromMediatorMessage $msg, EventBusInterface $eventBus): string
    {
        $eventBus->publish(new RecordedEvent($msg->payload));

        return 'published: '.$msg->payload;
    }
}

// ---------------------------------------------------------------------------
// Parameter-providing aspect fixtures — single entity, load+save
// ---------------------------------------------------------------------------

final readonly class ProcessEntityMessage
{
    public function __construct(public string $entityId) {}
}

final class TestEntity
{
    public bool $processed = false;

    public function __construct(public readonly string $id) {}

    public function process(): void
    {
        $this->processed = true;
    }
}

final class TestEntityRepository
{
    /** @var array<string, TestEntity> */
    public array $saved = [];

    public function load(object $message): TestEntity
    {
        return new TestEntity($message->entityId);
    }

    public function save(TestEntity $entity): void
    {
        $this->saved[$entity->id] = $entity;
    }
}

/**
 * Loads one TestEntity from $config['repoClass'] and saves it after $proceed.
 * The #[Around] matcher claims the first TestEntity dep-param it finds.
 */
final class LoadAndSaveAspect
{
    public function __construct(private readonly ContainerInterface $locator) {}

    #[Around(static function (\ReflectionMethod $m): ?MatchResult {
        foreach (array_slice($m->getParameters(), 1) as $i => $param) {
            $type = $param->getType();
            if ($type instanceof \ReflectionNamedType && TestEntity::class === $type->getName()) {
                return MatchResult::apply(config: ['repoClass' => TestEntityRepository::class], provides: [$i]);
            }
        }

        return null;
    })]
    public function execute(callable $proceed, object $message, MessageContext $context, array $config): mixed
    {
        $repo = $this->locator->get($config['repoClass']);
        $entity = $repo->load($message);
        $result = $proceed(provided: [$entity]);
        $repo->save($entity);

        return $result;
    }
}

final class EntityProcessingHandler
{
    #[Handles]
    public function process(ProcessEntityMessage $_msg, TestEntity $entity): string
    {
        $entity->process();

        return $entity->id;
    }
}

// ---------------------------------------------------------------------------
// Entity marker attribute — applied to domain entity classes so that generic
// aspects can discover them at compile time via reflection.
// ---------------------------------------------------------------------------

/** Marks a class as a domain entity and declares which message property holds its ID. */
#[\Attribute(\Attribute::TARGET_CLASS)]
final class Entity
{
    public function __construct(public readonly string $idFrom) {}
}

// ---------------------------------------------------------------------------
// Multi-param and split-aspect fixtures
// ---------------------------------------------------------------------------

#[Entity(idFrom: 'aId')]
final class EntityA
{
    public function __construct(public readonly string $id) {}
}

#[Entity(idFrom: 'bId')]
final class EntityB
{
    public function __construct(public readonly string $id) {}
}

final readonly class MultiEntityMessage
{
    public function __construct(public string $aId, public string $bId) {}
}

final readonly class SeparateEntitiesMessage
{
    public function __construct(public string $aId, public string $bId) {}
}

/** Repository for EntityA — extracts the ID from the message */
final class TestEntityARepository
{
    public function load(object $message): EntityA
    {
        return new EntityA($message->aId);
    }
}

/** Repository for EntityB — extracts the ID from the message */
final class TestEntityBRepository
{
    public function load(object $message): EntityB
    {
        return new EntityB($message->bId);
    }
}

/**
 * Single #[Around] that claims ALL EntityA+EntityB dep-params at once.
 * Loads them via $config['repos'] and passes them together to $proceed.
 */
final class BatchLoadAspect
{
    #[Around(static function (\ReflectionMethod $m): ?MatchResult {
        $indices = [];
        $repos = [];
        foreach (array_slice($m->getParameters(), 1) as $i => $param) {
            $type = $param->getType();
            if (! $type instanceof \ReflectionNamedType) {
                continue;
            }
            if (EntityA::class === $type->getName()) {
                $indices[] = $i;
                $repos[] = TestEntityARepository::class;
            } elseif (EntityB::class === $type->getName()) {
                $indices[] = $i;
                $repos[] = TestEntityBRepository::class;
            }
        }

        return [] !== $indices
            ? MatchResult::apply(config: ['repos' => $repos], provides: $indices)
            : null;
    })]
    public function execute(callable $proceed, object $message, MessageContext $context, array $config): mixed
    {
        $entities = array_map(
            static fn (string $repoClass) => new $repoClass()->load($message),
            $config['repos'],
        );

        return $proceed(provided: $entities);
    }
}

/**
 * Two #[Around] attributes on the same method — one for EntityA (priority 0, outer),
 * one for EntityB (priority 10, inner). Demonstrates nested proceed composition:
 * the outer $_p0 value remains available inside the deeper continuation.
 */
final class LoadAspect
{
    public function __construct(private readonly ContainerInterface $locator) {}

    #[Around(static function (\ReflectionMethod $m): ?MatchResult {
        foreach (array_slice($m->getParameters(), 1) as $i => $param) {
            $type = $param->getType();
            if ($type instanceof \ReflectionNamedType && EntityA::class === $type->getName()) {
                return MatchResult::apply(config: ['repoClass' => TestEntityARepository::class], provides: [$i]);
            }
        }

        return null;
    }, priority: 0)]
    #[Around(static function (\ReflectionMethod $m): ?MatchResult {
        foreach (array_slice($m->getParameters(), 1) as $i => $param) {
            $type = $param->getType();
            if ($type instanceof \ReflectionNamedType && EntityB::class === $type->getName()) {
                return MatchResult::apply(config: ['repoClass' => TestEntityBRepository::class], provides: [$i]);
            }
        }

        return null;
    }, priority: 10)]
    public function execute(callable $proceed, object $message, MessageContext $context, array $config): mixed
    {
        $entity = $this->locator->get($config['repoClass'])->load($message);

        return $proceed(provided: [$entity]);
    }
}

final class MultiEntityHandler
{
    #[Handles]
    public function handle(MultiEntityMessage $_msg, EntityA $a, EntityB $b): string
    {
        return $a->id.'+'.$b->id;
    }
}

final class SeparateEntitiesHandler
{
    #[Handles]
    public function handle(SeparateEntitiesMessage $_msg, EntityA $a, EntityB $b): string
    {
        return $a->id.'+'.$b->id;
    }
}

// ---------------------------------------------------------------------------
// EntityManager-based aspect fixtures
//
// A single EntityManager replaces per-entity repositories.
// The #[Around] closure on EntityManagerAspect::execute() scans handler
// parameters for classes marked with #[Entity] at compile time, embeds
// their class names and idFrom fields in runtimeConfig, and the aspect
// uses them at runtime to find() and save() via a single EM.
// ---------------------------------------------------------------------------

interface EntityManagerInterface
{
    public function find(string $class, mixed $id): object;

    public function save(object $entity): void;
}

final class TestEntityManager implements EntityManagerInterface
{
    /** @var list<array{class: string, id: mixed}> */
    public array $findCalls = [];
    /** @var array<class-string, array<mixed, object>> */
    public array $saved = [];

    public function find(string $class, mixed $id): object
    {
        $this->findCalls[] = ['class' => $class, 'id' => $id];

        return new $class($id);
    }

    public function save(object $entity): void
    {
        $this->saved[$entity::class][$entity->id] = $entity;
    }
}

/**
 * Runtime aspect: uses a single EntityManager to load every entity listed in
 * $config['entities'] and save them after $proceed returns.
 *
 * The compile-time logic lives in the #[Around] closure: it scans handler
 * dep-params for classes annotated with #[Entity] and builds the config.
 */
final class EntityManagerAspect
{
    public function __construct(private readonly EntityManagerInterface $em) {}

    #[Around(static function (\ReflectionMethod $m): ?MatchResult {
        $indices = [];
        $entities = [];
        foreach (array_slice($m->getParameters(), 1) as $i => $param) {
            $type = $param->getType();
            if (! $type instanceof \ReflectionNamedType || $type->isBuiltin()) {
                continue;
            }
            $refAttrs = new \ReflectionClass($type->getName())->getAttributes(Entity::class);
            if ([] === $refAttrs) {
                continue;
            }
            $entityAttr = $refAttrs[0]->newInstance();
            $indices[] = $i;
            $entities[] = ['class' => $type->getName(), 'idFrom' => $entityAttr->idFrom];
        }

        return [] !== $entities
            ? MatchResult::apply(config: ['entities' => $entities], provides: $indices)
            : null;
    })]
    public function execute(callable $proceed, object $message, MessageContext $context, array $config): mixed
    {
        $entities = [];
        foreach ($config['entities'] as ['class' => $class, 'idFrom' => $idFrom]) {
            $entities[] = $this->em->find($class, $message->{$idFrom});
        }
        $result = $proceed(provided: $entities);
        foreach ($entities as $entity) {
            $this->em->save($entity);
        }

        return $result;
    }
}

final readonly class ProcessEntitiesMessage
{
    public function __construct(public string $aId, public string $bId) {}
}

final class EntityManagerHandler
{
    #[Handles]
    public function handle(ProcessEntitiesMessage $_msg, EntityA $entityA, EntityB $entityB): string
    {
        return $entityA->id.'+'.$entityB->id;
    }
}

// ---------------------------------------------------------------------------
// MediatorCompilerPass — integration tests
// (ContainerBuilder wiring; no filesystem class loading)
// ---------------------------------------------------------------------------

test('compiler pass registers MediatorInterface definition in the container', function () use ($testOutputDir) {
    $container = new ContainerBuilder();
    $container->setDefinition(SalutationHandler::class, new Definition(SalutationHandler::class)->addTag('mediator.handler'));

    new MediatorCompilerPass($testOutputDir, 'CompiledMediatorPassTest1')->process($container);

    expect($container->hasDefinition(MediatorInterface::class))->toBeTrue();
    expect($container->getDefinition(MediatorInterface::class)->getArgument(0))->toBeInstanceOf(ServiceLocatorArgument::class);
});

test('compiler pass does nothing when no handlers are tagged', function () use ($testOutputDir) {
    $container = new ContainerBuilder();

    $container->addCompilerPass(new MediatorCompilerPass($testOutputDir, 'CompiledMediatorPassTest2'));
    $container->compile();

    expect($container->hasDefinition(MediatorInterface::class))->toBeFalse();
});

test('compiler pass throws on duplicate handlers for the same message', function () use ($testOutputDir) {
    $container = new ContainerBuilder();
    $container->setDefinition('handler_a', new Definition(SalutationHandler::class)->addTag('mediator.handler'));
    $container->setDefinition('handler_b', new Definition(SalutationHandler::class)->addTag('mediator.handler'));

    expect(fn () => new MediatorCompilerPass($testOutputDir, 'CompiledMediatorPassTest3')->process($container))->toThrow(\LogicException::class);
});

test('compiler pass adds aspect service IDs to the service locator', function () use ($testOutputDir) {
    $container = new ContainerBuilder();
    $container->setDefinition(AuditedHandler::class, new Definition(AuditedHandler::class)->addTag('mediator.handler'));
    $container->setDefinition(TestLogger::class, new Definition(TestLogger::class)->addTag('mediator.aspect'));

    new MediatorCompilerPass($testOutputDir, 'CompiledMediatorAopWiring')->process($container);

    $locatorServices = $container->getDefinition(MediatorInterface::class)->getArgument(0)->getValues();

    expect($locatorServices)
        ->toHaveKey(AuditedHandler::class)
        ->toHaveKey(TestLogger::class)
    ;
});

// ---------------------------------------------------------------------------
// End-to-end: generated class actually dispatches correctly
// ---------------------------------------------------------------------------

test('generated mediator routes messages to the correct handler method', function () use ($testOutputDir) {
    $container = new ContainerBuilder();
    $container->setDefinition(SalutationHandler::class, new Definition(SalutationHandler::class)->addTag('mediator.handler'));

    compileMediatorTestContainer($container, $testOutputDir, 'CompiledMediatorE2E1');

    $mediator = getTestMediator($container);

    expect($mediator->send(new HelloMessage('Alice')))->toBe('Hi, Alice')
        ->and($mediator->send(new GoodbyeMessage('Bob')))->toBe('Bye, Bob')
    ;
});

test('generated mediator uses a constant route table instead of a match expression', function () use ($testOutputDir) {
    $container = new ContainerBuilder();
    $container->setDefinition(SalutationHandler::class, new Definition(SalutationHandler::class)->addTag('mediator.handler'));

    compileMediatorTestContainer($container, $testOutputDir, 'CompiledMediatorRouteTable');

    $code = file_get_contents($testOutputDir.'/CompiledMediatorRouteTable.php');

    expect($code)->toBeString()
        ->toContain('private const ROUTES = [')
        ->toContain('\\'.HelloMessage::class.'::class')
        ->toContain('\\'.GoodbyeMessage::class.'::class')
        ->toContain("'dispatchHelloMessage_")
        ->toContain("'dispatchGoodbyeMessage_")
        ->toContain('$method = self::ROUTES[$message::class] ?? null;')
        ->toContain('return $this->{$method}($message, $messageContext);')
        ->not->toContain('return match ($message::class)');
});

test('generated mediator injects services from the locator into handler methods', function () use ($testOutputDir) {
    $container = new ContainerBuilder();
    $container->setDefinition(GreetWithServiceHandler::class, new Definition(GreetWithServiceHandler::class)->addTag('mediator.handler'));
    $container->setDefinition(GreeterService::class, new Definition(GreeterService::class));

    compileMediatorTestContainer($container, $testOutputDir, 'CompiledMediatorE2E2');

    $mediator = getTestMediator($container);

    expect($mediator->send(new PingMessage()))->toBe('Hello, pong!');
});

test('generated mediator throws NoHandlerFound for unregistered messages', function () use ($testOutputDir) {
    $container = new ContainerBuilder();
    $container->setDefinition(SalutationHandler::class, new Definition(SalutationHandler::class)->addTag('mediator.handler'));

    compileMediatorTestContainer($container, $testOutputDir, 'CompiledMediatorE2E3');

    $mediator = getTestMediator($container);

    expect(fn () => $mediator->send(new PingMessage()))
        ->toThrow(NoHandlerFound::class)
    ;
});

test('generated mediator calls before-advice then invokes the handler', function () use ($testOutputDir) {
    $container = new ContainerBuilder();
    $container->setDefinition(AuditedHandler::class, new Definition(AuditedHandler::class)->addTag('mediator.handler'));
    $container->setDefinition(TestLogger::class, new Definition(TestLogger::class)->addTag('mediator.aspect')->setPublic(true));

    compileMediatorTestContainer($container, $testOutputDir, 'CompiledMediatorAop1');

    $logger = $container->get(TestLogger::class);

    $mediator = getTestMediator($container);
    $message = new AuditedMessage('test');
    $result = $mediator->send($message);

    expect($result)->toBe('handled: test')
        ->and($logger->logged)->toHaveCount(1)
        ->and($logger->logged[0])->toBe($message)
    ;
});

test('generated mediator calls around-advice wrapping the handler', function () use ($testOutputDir) {
    $container = new ContainerBuilder();
    $container->setDefinition(WrappedHandler::class, new Definition(WrappedHandler::class)->addTag('mediator.handler'));
    $container->setDefinition(TestTransactionManager::class, new Definition(TestTransactionManager::class)->addTag('mediator.aspect')->setPublic(true));

    compileMediatorTestContainer($container, $testOutputDir, 'CompiledMediatorAop2');

    $txManager = $container->get(TestTransactionManager::class);
    $mediator = getTestMediator($container);
    $result = $mediator->send(new WrappedMessage('test'));

    expect($result)->toBe('wrapped: test')
        ->and($txManager->calls)->toBe(1)
    ;
});

test('generated mediator executes multiple around-advices ordered by priority', function () use ($testOutputDir) {
    $container = new ContainerBuilder();
    $container->setDefinition(DoubleWrappedHandler::class, new Definition(DoubleWrappedHandler::class)->addTag('mediator.handler'));
    $container->setDefinition(OuterAspectService::class, new Definition(OuterAspectService::class)->addTag('mediator.aspect')->setPublic(true));
    $container->setDefinition(InnerAspectService::class, new Definition(InnerAspectService::class)->addTag('mediator.aspect')->setPublic(true));

    compileMediatorTestContainer($container, $testOutputDir, 'CompiledMediatorAop3');

    $outer = $container->get(OuterAspectService::class);
    $inner = $container->get(InnerAspectService::class);
    $mediator = getTestMediator($container);
    $result = $mediator->send(new DoubleWrappedMessage('test'));

    expect($result)->toBe('double: test')
        // priority 0 → outermost
        ->and($outer->log)->toBe(['outer-before', 'outer-after'])
        // priority 10 → nested inside outer
        ->and($inner->log)->toBe(['inner-before', 'inner-after'])
    ;
});

test('generated mediator propagates message context through nested send calls from handlers', function () use ($testOutputDir) {
    $container = new ContainerBuilder();
    $container->setDefinition(ParentHandler::class, new Definition(ParentHandler::class)->addTag('mediator.handler'));
    $container->setDefinition(ChildHandler::class, new Definition(ChildHandler::class)->addTag('mediator.handler'));
    $container->setDefinition(MessageContextRecorder::class, new Definition(MessageContextRecorder::class)->addTag('mediator.aspect')->setPublic(true));

    compileMediatorTestContainer($container, $testOutputDir, 'CompiledMediatorNestedSendContext');

    $mediator = getTestMediator($container);
    $recorder = $container->get(MessageContextRecorder::class);

    expect($mediator->send(new ParentMessage('nested'), ['traceId' => 'trace-456']))->toBe('child: nested');
    assertMessageFlowContexts($recorder->entries, ParentMessage::class, ChildMessage::class, 'trace-456');
});

test('generated mediator accepts MessageContext objects at the public send boundary', function () use ($testOutputDir) {
    $container = new ContainerBuilder();
    $container->setDefinition(ParentHandler::class, new Definition(ParentHandler::class)->addTag('mediator.handler'));
    $container->setDefinition(ChildHandler::class, new Definition(ChildHandler::class)->addTag('mediator.handler'));
    $container->setDefinition(MessageContextRecorder::class, new Definition(MessageContextRecorder::class)->addTag('mediator.aspect')->setPublic(true));

    compileMediatorTestContainer($container, $testOutputDir, 'CompiledMediatorMessageContextInput');

    $mediator = getTestMediator($container);
    $recorder = $container->get(MessageContextRecorder::class);

    expect($mediator->send(new ParentMessage('typed'), MessageContext::coerce(['traceId' => 'trace-typed'])))->toBe('child: typed');
    assertMessageFlowContexts($recorder->entries, ParentMessage::class, ChildMessage::class, 'trace-typed');
});

test('generated mediator uses the configured message context propagator service', function () use ($testOutputDir) {
    $container = new ContainerBuilder();
    $container->setDefinition(ParentHandler::class, new Definition(ParentHandler::class)->addTag('mediator.handler'));
    $container->setDefinition(ChildHandler::class, new Definition(ChildHandler::class)->addTag('mediator.handler'));
    $container->setDefinition(MessageContextRecorder::class, new Definition(MessageContextRecorder::class)->addTag('mediator.aspect')->setPublic(true));
    $container->setDefinition(MessageContextPropagatorInterface::class, (new Definition(FixedMessageContextPropagator::class))->setPublic(true));

    compileMediatorTestContainer($container, $testOutputDir, 'CompiledMediatorCustomPropagator');

    $mediator = getTestMediator($container);
    $recorder = $container->get(MessageContextRecorder::class);

    expect($mediator->send(new ParentMessage('custom'), ['traceId' => 'trace-custom']))->toBe('child: custom')
        ->and($recorder->entries[0]['context'][MessageContextKeys::MESSAGE_ID])->toBe('fixed-root')
        ->and($recorder->entries[0]['context'][MessageContextKeys::CORRELATION_ID])->toBe('fixed-correlation')
        ->and($recorder->entries[1]['context'][MessageContextKeys::MESSAGE_ID])->toBe('fixed-child')
        ->and($recorder->entries[1]['context'][MessageContextKeys::CORRELATION_ID])->toBe('fixed-correlation')
        ->and($recorder->entries[1]['context'][MessageContextKeys::CAUSATION_ID])->toBe('fixed-root');
});

test('generated mediator propagates message context to event bus injected into handlers', function () use ($testOutputDir) {
    $container = new ContainerBuilder();
    $container->setDefinition(EventPublishingHandler::class, new Definition(EventPublishingHandler::class)->addTag('mediator.handler'));
    $container->setDefinition(RecordingEventBus::class, new Definition(RecordingEventBus::class)->setPublic(true));
    $container->setAlias(EventBusInterface::class, RecordingEventBus::class)->setPublic(true);

    compileMediatorTestContainer($container, $testOutputDir, 'CompiledMediatorEventBusContext');

    $mediator = getTestMediator($container);
    $eventBus = $container->get(RecordingEventBus::class);

    expect($mediator->send(new PublishedFromMediatorMessage('bridge'), ['traceId' => 'trace-eb']))->toBe('published: bridge')
        ->and($eventBus->calls)->toHaveCount(1)
        ->and($eventBus->calls[0]['event'])->toBeInstanceOf(RecordedEvent::class)
        ->and($eventBus->calls[0]['context']['traceId'])->toBe('trace-eb')
        ->and($eventBus->calls[0]['context'])->toHaveKey(MessageContextKeys::MESSAGE_ID)
        ->and($eventBus->calls[0]['context'])->toHaveKey(MessageContextKeys::CORRELATION_ID);
});

test('compiler pass excludes claimed dep types from the service locator', function () use ($testOutputDir) {
    $container = new ContainerBuilder();
    $container->setDefinition(EntityProcessingHandler::class, new Definition(EntityProcessingHandler::class)->addTag('mediator.handler'));
    $container->setDefinition(LoadAndSaveAspect::class, new Definition(LoadAndSaveAspect::class)->addTag('mediator.aspect'));

    new MediatorCompilerPass($testOutputDir, 'CompiledMediatorParamProviding0')->process($container);

    $locatorServices = $container->getDefinition(MediatorInterface::class)->getArgument(0)->getValues();

    expect($locatorServices)
        ->toHaveKey(EntityProcessingHandler::class)
        ->toHaveKey(LoadAndSaveAspect::class)
        // TestEntity is a claimed dep — the aspect provides it, so it must NOT be in the locator
        ->not->toHaveKey(TestEntity::class)
    ;
});

test('generated mediator uses parameter-providing aspect to inject and save entity', function () use ($testOutputDir) {
    $container = new ContainerBuilder();
    $container->setDefinition(EntityProcessingHandler::class, new Definition(EntityProcessingHandler::class)->addTag('mediator.handler'));
    $container->setDefinition(TestEntityRepository::class, new Definition(TestEntityRepository::class)->setPublic(true));
    $container->setDefinition(
        LoadAndSaveAspect::class,
        new Definition(LoadAndSaveAspect::class, [
            new ServiceLocatorArgument([TestEntityRepository::class => new Reference(TestEntityRepository::class)]),
        ])->addTag('mediator.aspect')->setPublic(true)
    );

    compileMediatorTestContainer($container, $testOutputDir, 'CompiledMediatorParamProviding1');

    // TestEntity is NOT in the mediator locator — LoadAndSaveAspect provides it
    $repo = $container->get(TestEntityRepository::class);
    $mediator = getTestMediator($container);
    $result = $mediator->send(new ProcessEntityMessage('entity-123'));

    expect($result)->toBe('entity-123')
        ->and($repo->saved)->toHaveKey('entity-123')
        ->and($repo->saved['entity-123']->processed)->toBeTrue()
    ;
});

test('one parameter-providing aspect can claim multiple handler parameters at once', function () use ($testOutputDir) {
    $container = new ContainerBuilder();
    $container->setDefinition(MultiEntityHandler::class, new Definition(MultiEntityHandler::class)->addTag('mediator.handler'));
    $container->setDefinition(
        BatchLoadAspect::class,
        new Definition(BatchLoadAspect::class)
            ->addTag('mediator.aspect')->setPublic(true)
    );

    compileMediatorTestContainer($container, $testOutputDir, 'CompiledMediatorMultiParam1');

    $mediator = getTestMediator($container);
    $result = $mediator->send(new MultiEntityMessage('a-1', 'b-2'));

    expect($result)->toBe('a-1+b-2');
});

test('two #[Around] on the same aspect method each claim a different parameter and compose through nested proceed continuations', function () use ($testOutputDir) {
    // A single LoadAspect service produces two separate advices — one per #[Around] attribute.
    // Each call receives a different $config; one locator entry serves both.
    $container = new ContainerBuilder();
    $container->setDefinition(SeparateEntitiesHandler::class, new Definition(SeparateEntitiesHandler::class)->addTag('mediator.handler'));
    $container->setDefinition(TestEntityARepository::class, new Definition(TestEntityARepository::class));
    $container->setDefinition(TestEntityBRepository::class, new Definition(TestEntityBRepository::class));
    $container->setDefinition(
        LoadAspect::class,
        new Definition(LoadAspect::class, [
            new ServiceLocatorArgument([
                TestEntityARepository::class => new Reference(TestEntityARepository::class),
                TestEntityBRepository::class => new Reference(TestEntityBRepository::class),
            ]),
        ])->addTag('mediator.aspect')->setPublic(true)
    );

    compileMediatorTestContainer($container, $testOutputDir, 'CompiledMediatorMultiParam2');

    // Neither EntityA nor EntityB is in the mediator locator — LoadAspect provides both
    $mediator = getTestMediator($container);
    $result = $mediator->send(new SeparateEntitiesMessage('a-1', 'b-2'));

    // EntityA from priority-0 advice (outer), EntityB from priority-10 advice (inner);
    // the generated proceed closures keep the outer provided value available inward.
    expect($result)->toBe('a-1+b-2');
});

test('#[Around] closure discovers #[Entity] parameters at compile time and delegates to a single EntityManager at runtime', function () use ($testOutputDir) {
    $container = new ContainerBuilder();
    $container->setDefinition(EntityManagerHandler::class, new Definition(EntityManagerHandler::class)->addTag('mediator.handler'));
    $container->setDefinition(TestEntityManager::class, new Definition(TestEntityManager::class)->setPublic(true));
    $container->setDefinition(
        EntityManagerAspect::class,
        new Definition(EntityManagerAspect::class, [new Reference(TestEntityManager::class)])
            ->addTag('mediator.aspect')->setPublic(true)
    );

    compileMediatorTestContainer($container, $testOutputDir, 'CompiledMediatorEm');

    // Neither EntityA nor EntityB is in the mediator locator — EntityManagerAspect provides both
    $em = $container->get(TestEntityManager::class);
    $mediator = getTestMediator($container);
    $result = $mediator->send(new ProcessEntitiesMessage('a-1', 'b-2'));

    expect($result)->toBe('a-1+b-2')
        ->and($em->findCalls)->toHaveCount(2)
        ->and($em->findCalls[0])->toBe(['class' => EntityA::class, 'id' => 'a-1'])
        ->and($em->findCalls[1])->toBe(['class' => EntityB::class, 'id' => 'b-2'])
        ->and($em->saved)->toHaveKey(EntityA::class)
        ->and($em->saved)->toHaveKey(EntityB::class)
    ;
});

// ---------------------------------------------------------------------------
// After-type advice fixtures
// ---------------------------------------------------------------------------

final readonly class ObservedMessage
{
    public function __construct(public string $payload) {}
}

final readonly class FailingMessage {}

final class ObservedHandler
{
    #[Handles]
    public function handle(ObservedMessage $msg): string
    {
        return 'observed: '.$msg->payload;
    }
}

final class FailingHandler
{
    #[Handles]
    public function handle(FailingMessage $_msg): string
    {
        throw new \RuntimeException('handler failed');
    }
}

/** Captures the return value via #[AfterReturning]. */
final class ResultInspector
{
    public mixed $captured = null;

    #[AfterReturning(static function (\ReflectionMethod $m): ?MatchResult {
        return MatchResult::apply();
    })]
    public function inspect(object $message, MessageContext $context, mixed $returnValue): void
    {
        $this->captured = $returnValue;
    }
}

/** Captures the thrown exception via #[AfterThrowing]. */
final class ErrorRecorder
{
    public ?\Throwable $caught = null;

    #[AfterThrowing(static function (\ReflectionMethod $m): ?MatchResult {
        return MatchResult::apply();
    })]
    public function record(object $message, MessageContext $context, \Throwable $e): void
    {
        $this->caught = $e;
    }
}

/** Counts invocations via #[After] (runs regardless of outcome). */
final class Cleaner
{
    public int $calls = 0;

    #[After(static function (\ReflectionMethod $m): ?MatchResult {
        return MatchResult::apply();
    })]
    public function cleanup(object $message, MessageContext $context): void
    {
        ++$this->calls;
    }
}

// ---------------------------------------------------------------------------
// After-type advice tests
// ---------------------------------------------------------------------------

test('AfterReturning advice receives the handler return value', function () use ($testOutputDir) {
    $container = new ContainerBuilder();
    $container->setDefinition(ObservedHandler::class, new Definition(ObservedHandler::class)->addTag('mediator.handler'));
    $container->setDefinition(ResultInspector::class, new Definition(ResultInspector::class)->addTag('mediator.aspect')->setPublic(true));

    compileMediatorTestContainer($container, $testOutputDir, 'CompiledMediatorAfterReturning1');

    $inspector = $container->get(ResultInspector::class);
    $mediator = getTestMediator($container);
    $result = $mediator->send(new ObservedMessage('hello'));

    expect($result)->toBe('observed: hello')
        ->and($inspector->captured)->toBe('observed: hello')
    ;
});

test('AfterThrowing advice is called when handler throws and exception still propagates', function () use ($testOutputDir) {
    $container = new ContainerBuilder();
    $container->setDefinition(FailingHandler::class, new Definition(FailingHandler::class)->addTag('mediator.handler'));
    $container->setDefinition(ErrorRecorder::class, new Definition(ErrorRecorder::class)->addTag('mediator.aspect')->setPublic(true));

    compileMediatorTestContainer($container, $testOutputDir, 'CompiledMediatorAfterThrowing1');

    $recorder = $container->get(ErrorRecorder::class);
    $mediator = getTestMediator($container);

    expect(fn () => $mediator->send(new FailingMessage()))->toThrow(\RuntimeException::class, 'handler failed')
        ->and($recorder->caught)->toBeInstanceOf(\RuntimeException::class)
    ;
});

test('After advice runs after a successful handler invocation', function () use ($testOutputDir) {
    $container = new ContainerBuilder();
    $container->setDefinition(ObservedHandler::class, new Definition(ObservedHandler::class)->addTag('mediator.handler'));
    $container->setDefinition(Cleaner::class, new Definition(Cleaner::class)->addTag('mediator.aspect')->setPublic(true));

    compileMediatorTestContainer($container, $testOutputDir, 'CompiledMediatorAfter1');

    $cleaner = $container->get(Cleaner::class);
    $mediator = getTestMediator($container);
    $result = $mediator->send(new ObservedMessage('world'));

    expect($result)->toBe('observed: world')
        ->and($cleaner->calls)->toBe(1)
    ;
});

test('After advice runs even when handler throws', function () use ($testOutputDir) {
    $container = new ContainerBuilder();
    $container->setDefinition(FailingHandler::class, new Definition(FailingHandler::class)->addTag('mediator.handler'));
    $container->setDefinition(Cleaner::class, new Definition(Cleaner::class)->addTag('mediator.aspect')->setPublic(true));

    compileMediatorTestContainer($container, $testOutputDir, 'CompiledMediatorAfter2');

    $cleaner = $container->get(Cleaner::class);
    $mediator = getTestMediator($container);

    expect(fn () => $mediator->send(new FailingMessage()))->toThrow(\RuntimeException::class)
        ->and($cleaner->calls)->toBe(1)
    ;
});

// ---------------------------------------------------------------------------
// provides-validation fixtures and test
// ---------------------------------------------------------------------------

/** An #[After] advice that illegally claims a dep-param slot via provides. */
final class IllegalProvidesAspect
{
    #[After(static function (\ReflectionMethod $m): ?MatchResult {
        return MatchResult::apply(provides: [0]);
    })]
    public function cleanup(object $message, MessageContext $context): void {}
}

test('compiler pass throws when an after-type advice returns provides', function () use ($testOutputDir) {
    $container = new ContainerBuilder();
    $container->setDefinition(ObservedHandler::class, new Definition(ObservedHandler::class)->addTag('mediator.handler'));
    $container->setDefinition(IllegalProvidesAspect::class, new Definition(IllegalProvidesAspect::class)->addTag('mediator.aspect'));

    expect(fn () => new MediatorCompilerPass($testOutputDir, 'CompiledMediatorProvidesValidation')->process($container))
        ->toThrow(\LogicException::class, 'only #[Around] and #[Before] advices may provide dep-param slots')
    ;
});

// ---------------------------------------------------------------------------
// Self-handler fixtures
// Message classes that handle themselves: #[Handles] method has only dep params
// ($this is the message — no message param in the method signature).
// ---------------------------------------------------------------------------

final class SelfGreetMessage
{
    public function __construct(public readonly string $name) {}

    #[Handles]
    public function greet(): string
    {
        return 'Hi, '.$this->name.' (self)';
    }
}

final class SelfGreetWithServiceMessage
{
    public function __construct(public readonly string $name) {}

    #[Handles]
    public function greet(GreeterService $greeter): string
    {
        return $greeter->greet($this->name);
    }
}

// ---------------------------------------------------------------------------
// Self-handler tests
// ---------------------------------------------------------------------------

test('compiler pass does nothing when only self-handler tag is absent and no regular handlers', function () use ($testOutputDir) {
    $container = new ContainerBuilder();
    $container->addCompilerPass(new MediatorCompilerPass($testOutputDir, 'CompiledMediatorSelfHandler0'));
    $container->compile();

    expect($container->hasDefinition(MediatorInterface::class))->toBeFalse();
});

test('generated mediator dispatches to a no-dep self-handler method', function () use ($testOutputDir) {
    $container = new ContainerBuilder();
    $container->setDefinition(
        SelfGreetMessage::class,
        new Definition(SelfGreetMessage::class)->addTag('mediator.self-handler'),
    );

    compileMediatorTestContainer($container, $testOutputDir, 'CompiledMediatorSelfHandler1');

    $mediator = getTestMediator($container);

    expect($mediator->send(new SelfGreetMessage('Alice')))->toBe('Hi, Alice (self)');
});

test('generated mediator injects deps from locator into a self-handler method', function () use ($testOutputDir) {
    $container = new ContainerBuilder();
    $container->setDefinition(
        SelfGreetWithServiceMessage::class,
        new Definition(SelfGreetWithServiceMessage::class)->addTag('mediator.self-handler'),
    );
    $container->setDefinition(GreeterService::class, new Definition(GreeterService::class));

    compileMediatorTestContainer($container, $testOutputDir, 'CompiledMediatorSelfHandler2');

    $mediator = getTestMediator($container);

    expect($mediator->send(new SelfGreetWithServiceMessage('Bob')))->toBe('Hello, Bob!');
});

test('self-handler and regular handler can coexist in the same container', function () use ($testOutputDir) {
    $container = new ContainerBuilder();
    $container->setDefinition(SalutationHandler::class, new Definition(SalutationHandler::class)->addTag('mediator.handler'));
    $container->setDefinition(
        SelfGreetMessage::class,
        new Definition(SelfGreetMessage::class)->addTag('mediator.self-handler'),
    );

    compileMediatorTestContainer($container, $testOutputDir, 'CompiledMediatorSelfHandler3');

    $mediator = getTestMediator($container);

    expect($mediator->send(new HelloMessage('Alice')))->toBe('Hi, Alice')
        ->and($mediator->send(new SelfGreetMessage('Bob')))->toBe('Hi, Bob (self)')
    ;
});

test('before-advice applies to self-handler method', function () use ($testOutputDir) {
    $container = new ContainerBuilder();
    $container->setDefinition(
        SelfGreetMessage::class,
        new Definition(SelfGreetMessage::class)->addTag('mediator.self-handler'),
    );
    $container->setDefinition(TestLogger::class, new Definition(TestLogger::class)->addTag('mediator.aspect')->setPublic(true));

    compileMediatorTestContainer($container, $testOutputDir, 'CompiledMediatorSelfHandler4');

    $logger = $container->get(TestLogger::class);
    $mediator = getTestMediator($container);
    $message = new SelfGreetMessage('Carol');
    $result = $mediator->send($message);

    expect($result)->toBe('Hi, Carol (self)')
        ->and($logger->logged)->toHaveCount(1)
        ->and($logger->logged[0])->toBe($message);
});
