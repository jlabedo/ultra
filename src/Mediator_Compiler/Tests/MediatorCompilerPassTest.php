<?php

declare(strict_types=1);

namespace Ultra\Mediator_Compiler\Tests;

use Symfony\Component\DependencyInjection\Argument\ServiceLocatorArgument;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Definition;
use Symfony\Component\DependencyInjection\ServiceLocator;
use Symfony\Contracts\Service\ServiceProviderInterface;
use Ultra\Mediator\Attribute\After;
use Ultra\Mediator\Attribute\AfterReturning;
use Ultra\Mediator\Attribute\AfterThrowing;
use Ultra\Mediator\Attribute\Around;
use Ultra\Mediator\Attribute\Before;
use Ultra\Mediator\Attribute\Handles;
use Ultra\Mediator\Attribute\MatchResult;
use Ultra\Mediator\MediatorInterface;
use Ultra\Mediator_Compiler\MediatorCompilerPass;

// ---------------------------------------------------------------------------
// Shared temp directory for this test run
// ---------------------------------------------------------------------------

$testOutputDir = dirname(__DIR__, 3) . '/var/mediator_compiler_tests';

// ---------------------------------------------------------------------------
// Message fixtures
// ---------------------------------------------------------------------------

final readonly class HelloMessage
{
    public function __construct(public string $name)
    {
    }
}

final readonly class GoodbyeMessage
{
    public function __construct(public string $name)
    {
    }
}

final readonly class PingMessage
{
}

final readonly class AuditedMessage
{
    public function __construct(public string $payload)
    {
    }
}

final readonly class WrappedMessage
{
    public function __construct(public string $payload)
    {
    }
}

final readonly class DoubleWrappedMessage
{
    public function __construct(public string $payload)
    {
    }
}

// ---------------------------------------------------------------------------
// Service fixtures (plain collaborators)
// ---------------------------------------------------------------------------

final class GreeterService
{
    public function greet(string $name): string
    {
        return 'Hello, ' . $name . '!';
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
    public function log(object $message, array $context): void
    {
        $this->logged[] = $message;
    }
}

final class TestTransactionManager
{
    public int $calls = 0;

    #[Around(static function (\ReflectionMethod $m): ?MatchResult {
            return MatchResult::apply(); // apply to every handler registered alongside this aspect
            })]
    public function execute(callable $proceed, object $message, array $context): mixed
    {
        $this->calls++;
        return $proceed($message, $context);
    }
}

final class OuterAspectService
{
    /** @var list<string> */
    public array $log = [];

    #[Around(static function (\ReflectionMethod $m): ?MatchResult {
            return MatchResult::apply();
            }, priority: 10)]
    public function wrap(callable $proceed, object $message, array $context): mixed
    {
        $this->log[] = 'outer-before';
        $result = $proceed($message, $context);
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
            }, priority: 0)]
    public function wrap(callable $proceed, object $message, array $context): mixed
    {
        $this->log[] = 'inner-before';
        $result = $proceed($message, $context);
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
        return 'Hi, ' . $msg->name;
    }

    #[Handles]
    public function goodbye(GoodbyeMessage $msg): string
    {
        return 'Bye, ' . $msg->name;
    }

    /** Regular method — must be ignored */
    public function helper(): void
    {
    }
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
        return 'handled: ' . $msg->payload;
    }
}

final class WrappedHandler
{
    #[Handles]
    public function handle(WrappedMessage $msg): string
    {
        return 'wrapped: ' . $msg->payload;
    }
}

final class DoubleWrappedHandler
{
    #[Handles]
    public function handle(DoubleWrappedMessage $msg): string
    {
        return 'double: ' . $msg->payload;
    }
}

// ---------------------------------------------------------------------------
// Parameter-providing aspect fixtures — single entity, load+save
// ---------------------------------------------------------------------------

final readonly class ProcessEntityMessage
{
    public function __construct(public string $entityId)
    {
    }
}

final class TestEntity
{
    public bool $processed = false;

    public function __construct(public readonly string $id)
    {
    }

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
    public function __construct(private readonly ServiceProviderInterface $locator)
    {
    }

    #[Around(static function (\ReflectionMethod $m): ?MatchResult {
            foreach (array_slice($m->getParameters(), 1) as $i => $param) {
                $type = $param->getType();
                    if ($type instanceof \ReflectionNamedType && $type->getName() === TestEntity::class) {
                    return MatchResult::apply(config: ['repoClass' => TestEntityRepository::class], provides: [$i]);
                    }
                }
            return null;
            })]
    public function execute(callable $proceed, object $message, array $context, array $config): mixed
    {
        $repo = $this->locator->get($config['repoClass']);
        $entity = $repo->load($message);
        $result = $proceed($message, $context, $entity);
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
    public function __construct(public readonly string $idFrom)
    {
    }
}

// ---------------------------------------------------------------------------
// Multi-param and split-aspect fixtures
// ---------------------------------------------------------------------------

#[Entity(idFrom: 'aId')]
final class EntityA
{
    public function __construct(public readonly string $id)
    {
    }
}

#[Entity(idFrom: 'bId')]
final class EntityB
{
    public function __construct(public readonly string $id)
    {
    }
}

final readonly class MultiEntityMessage
{
    public function __construct(public string $aId, public string $bId)
    {
    }
}

final readonly class SeparateEntitiesMessage
{
    public function __construct(public string $aId, public string $bId)
    {
    }
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
    public function __construct(private readonly ServiceProviderInterface $locator)
    {
    }

    #[Around(static function (\ReflectionMethod $m): ?MatchResult {
            $indices = [];
            $repos = [];
            foreach (array_slice($m->getParameters(), 1) as $i => $param) {
                $type = $param->getType();
                    if (!$type instanceof \ReflectionNamedType) {
                    continue;
                    }
                    if ($type->getName() === EntityA::class) {
                    $indices[] = $i;
                    $repos[] = TestEntityARepository::class;
                    } elseif ($type->getName() === EntityB::class) {
                    $indices[] = $i;
                    $repos[] = TestEntityBRepository::class;
                    }
                }
            return $indices !== []
                ? MatchResult::apply(config: ['repos' => $repos], provides: $indices)
                : null;
            })]
    public function execute(callable $proceed, object $message, array $context, array $config): mixed
    {
        $entities = array_map(
            static fn(string $repoClass) => (new $repoClass())->load($message),
            $config['repos'],
        );
        return $proceed($message, $context, ...$entities);
    }
}

/**
 * Two #[Around] attributes on the same method — one for EntityA (priority 10, outer),
 * one for EntityB (priority 0, inner). Demonstrates arrow-function capture:
 * the outer $_p0 is automatically captured inside the inner lambda.
 */
final class LoadAspect
{
    public function __construct(private readonly ServiceProviderInterface $locator)
    {
    }

    #[Around(static function (\ReflectionMethod $m): ?MatchResult {
            foreach (array_slice($m->getParameters(), 1) as $i => $param) {
                $type = $param->getType();
                    if ($type instanceof \ReflectionNamedType && $type->getName() === EntityA::class) {
                    return MatchResult::apply(config: ['repoClass' => TestEntityARepository::class], provides: [$i]);
                    }
                }
            return null;
            }, priority: 10)]
    #[Around(static function (\ReflectionMethod $m): ?MatchResult {
            foreach (array_slice($m->getParameters(), 1) as $i => $param) {
                $type = $param->getType();
                    if ($type instanceof \ReflectionNamedType && $type->getName() === EntityB::class) {
                    return MatchResult::apply(config: ['repoClass' => TestEntityBRepository::class], provides: [$i]);
                    }
                }
            return null;
            }, priority: 0)]
    public function execute(callable $proceed, object $message, array $context, array $config): mixed
    {
        $entity = $this->locator->get($config['repoClass'])->load($message);
        return $proceed($message, $context, $entity);
    }
}

final class MultiEntityHandler
{
    #[Handles]
    public function handle(MultiEntityMessage $_msg, EntityA $a, EntityB $b): string
    {
        return $a->id . '+' . $b->id;
    }
}

final class SeparateEntitiesHandler
{
    #[Handles]
    public function handle(SeparateEntitiesMessage $_msg, EntityA $a, EntityB $b): string
    {
        return $a->id . '+' . $b->id;
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
    public function __construct(private readonly EntityManagerInterface $em)
    {
    }

    #[Around(static function (\ReflectionMethod $m): ?MatchResult {
            $indices = [];
            $entities = [];
            foreach (array_slice($m->getParameters(), 1) as $i => $param) {
                $type = $param->getType();
                    if (!$type instanceof \ReflectionNamedType || $type->isBuiltin()) {
                    continue;
                    }
                $refAttrs = (new \ReflectionClass($type->getName()))->getAttributes(Entity::class);
                    if ($refAttrs === []) {
                    continue;
                    }
                $entityAttr = $refAttrs[0]->newInstance();
                $indices[] = $i;
                $entities[] = ['class' => $type->getName(), 'idFrom' => $entityAttr->idFrom];
                }
            return $entities !== []
                ? MatchResult::apply(config: ['entities' => $entities], provides: $indices)
                : null;
            })]
    public function execute(callable $proceed, object $message, array $context, array $config): mixed
    {
        $entities = [];
        foreach ($config['entities'] as ['class' => $class, 'idFrom' => $idFrom]) {
            $entities[] = $this->em->find($class, $message->$idFrom);
        }
        $result = $proceed($message, $context, ...$entities);
        foreach ($entities as $entity) {
            $this->em->save($entity);
        }
        return $result;
    }
}

final readonly class ProcessEntitiesMessage
{
    public function __construct(public string $aId, public string $bId)
    {
    }
}

final class EntityManagerHandler
{
    #[Handles]
    public function handle(ProcessEntitiesMessage $_msg, EntityA $entityA, EntityB $entityB): string
    {
        return $entityA->id . '+' . $entityB->id;
    }
}

// ---------------------------------------------------------------------------
// MediatorCompilerPass — integration tests
// (ContainerBuilder wiring; no filesystem class loading)
// ---------------------------------------------------------------------------

test('compiler pass registers MediatorInterface definition in the container', function () use ($testOutputDir) {
    $container = new ContainerBuilder();
    $container->setDefinition(SalutationHandler::class, (new Definition(SalutationHandler::class))->addTag('mediator.handler'));

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
    $container->setDefinition('handler_a', (new Definition(SalutationHandler::class))->addTag('mediator.handler'));
    $container->setDefinition('handler_b', (new Definition(SalutationHandler::class))->addTag('mediator.handler'));

    expect(fn() => new MediatorCompilerPass($testOutputDir, 'CompiledMediatorPassTest3')->process($container))->toThrow(\LogicException::class);
});

test('compiler pass adds aspect service IDs to the service locator', function () use ($testOutputDir) {
    $container = new ContainerBuilder();
    $container->setDefinition(AuditedHandler::class, (new Definition(AuditedHandler::class))->addTag('mediator.handler'));
    $container->setDefinition(TestLogger::class, (new Definition(TestLogger::class))->addTag('mediator.aspect'));

    new MediatorCompilerPass($testOutputDir, 'CompiledMediatorAopWiring')->process($container);

    $locatorServices = $container->getDefinition(MediatorInterface::class)->getArgument(0)->getValues();

    expect($locatorServices)
        ->toHaveKey(AuditedHandler::class)
        ->toHaveKey(TestLogger::class);
});

// ---------------------------------------------------------------------------
// End-to-end: generated class actually dispatches correctly
// ---------------------------------------------------------------------------

test('generated mediator routes messages to the correct handler method', function () use ($testOutputDir) {
    $container = new ContainerBuilder();
    $container->setDefinition(SalutationHandler::class, (new Definition(SalutationHandler::class))->addTag('mediator.handler'));

    $container->addCompilerPass(new MediatorCompilerPass($testOutputDir, 'CompiledMediatorE2E1'));
    $container->compile();

    require_once $testOutputDir . '/CompiledMediatorE2E1.php';

    $locator = new ServiceLocator([
        SalutationHandler::class => fn() => new SalutationHandler(),
    ]);

    $mediator = new \CompiledMediatorE2E1($locator);

    expect($mediator->send(new HelloMessage('Alice')))->toBe('Hi, Alice')
        ->and($mediator->send(new GoodbyeMessage('Bob')))->toBe('Bye, Bob');
});

test('generated mediator injects services from the locator into handler methods', function () use ($testOutputDir) {
    $container = new ContainerBuilder();
    $container->setDefinition(GreetWithServiceHandler::class, (new Definition(GreetWithServiceHandler::class))->addTag('mediator.handler'));
    $container->setDefinition(GreeterService::class, new Definition(GreeterService::class));

    $container->addCompilerPass(new MediatorCompilerPass($testOutputDir, 'CompiledMediatorE2E2'));
    $container->compile();

    require_once $testOutputDir . '/CompiledMediatorE2E2.php';

    $locator = new ServiceLocator([
        GreetWithServiceHandler::class => fn() => new GreetWithServiceHandler(),
        GreeterService::class => fn() => new GreeterService(),
    ]);

    $mediator = new \CompiledMediatorE2E2($locator);

    expect($mediator->send(new PingMessage()))->toBe('Hello, pong!');
});

test('generated mediator throws NoHandlerFound for unregistered messages', function () use ($testOutputDir) {
    $container = new ContainerBuilder();
    $container->setDefinition(SalutationHandler::class, (new Definition(SalutationHandler::class))->addTag('mediator.handler'));

    $container->addCompilerPass(new MediatorCompilerPass($testOutputDir, 'CompiledMediatorE2E3'));
    $container->compile();

    require_once $testOutputDir . '/CompiledMediatorE2E3.php';

    $locator = new ServiceLocator([SalutationHandler::class => fn() => new SalutationHandler()]);
    $mediator = new \CompiledMediatorE2E3($locator);

    expect(fn() => $mediator->send(new PingMessage()))
        ->toThrow(\Ultra\Mediator\Exception\NoHandlerFound::class);
});

test('generated mediator calls before-advice then invokes the handler', function () use ($testOutputDir) {
    $container = new ContainerBuilder();
    $container->setDefinition(AuditedHandler::class, (new Definition(AuditedHandler::class))->addTag('mediator.handler'));
    $container->setDefinition(TestLogger::class, (new Definition(TestLogger::class))->addTag('mediator.aspect'));

    $container->addCompilerPass(new MediatorCompilerPass($testOutputDir, 'CompiledMediatorAop1'));
    $container->compile();

    require_once $testOutputDir . '/CompiledMediatorAop1.php';

    $logger = new TestLogger();
    $locator = new ServiceLocator([
        AuditedHandler::class => fn() => new AuditedHandler(),
        TestLogger::class => fn() => $logger,
    ]);

    $mediator = new \CompiledMediatorAop1($locator);
    $message = new AuditedMessage('test');
    $result = $mediator->send($message);

    expect($result)->toBe('handled: test')
        ->and($logger->logged)->toHaveCount(1)
        ->and($logger->logged[0])->toBe($message);
});

test('generated mediator calls around-advice wrapping the handler', function () use ($testOutputDir) {
    $container = new ContainerBuilder();
    $container->setDefinition(WrappedHandler::class, (new Definition(WrappedHandler::class))->addTag('mediator.handler'));
    $container->setDefinition(TestTransactionManager::class, (new Definition(TestTransactionManager::class))->addTag('mediator.aspect'));

    $container->addCompilerPass(new MediatorCompilerPass($testOutputDir, 'CompiledMediatorAop2'));
    $container->compile();

    require_once $testOutputDir . '/CompiledMediatorAop2.php';

    $txManager = new TestTransactionManager();
    $locator = new ServiceLocator([
        WrappedHandler::class => fn() => new WrappedHandler(),
        TestTransactionManager::class => fn() => $txManager,
    ]);

    $mediator = new \CompiledMediatorAop2($locator);
    $result = $mediator->send(new WrappedMessage('test'));

    expect($result)->toBe('wrapped: test')
        ->and($txManager->calls)->toBe(1);
});

test('generated mediator executes multiple around-advices ordered by priority', function () use ($testOutputDir) {
    $container = new ContainerBuilder();
    $container->setDefinition(DoubleWrappedHandler::class, (new Definition(DoubleWrappedHandler::class))->addTag('mediator.handler'));
    $container->setDefinition(OuterAspectService::class, (new Definition(OuterAspectService::class))->addTag('mediator.aspect'));
    $container->setDefinition(InnerAspectService::class, (new Definition(InnerAspectService::class))->addTag('mediator.aspect'));

    $container->addCompilerPass(new MediatorCompilerPass($testOutputDir, 'CompiledMediatorAop3'));
    $container->compile();

    require_once $testOutputDir . '/CompiledMediatorAop3.php';

    $outer = new OuterAspectService();
    $inner = new InnerAspectService();
    $locator = new ServiceLocator([
        DoubleWrappedHandler::class => fn() => new DoubleWrappedHandler(),
        OuterAspectService::class => fn() => $outer,
        InnerAspectService::class => fn() => $inner,
    ]);

    $mediator = new \CompiledMediatorAop3($locator);
    $result = $mediator->send(new DoubleWrappedMessage('test'));

    expect($result)->toBe('double: test')
        // priority 10 → outermost
        ->and($outer->log)->toBe(['outer-before', 'outer-after'])
        // priority 0 → nested inside outer
        ->and($inner->log)->toBe(['inner-before', 'inner-after']);
});

test('compiler pass excludes claimed dep types from the service locator', function () use ($testOutputDir) {
    $container = new ContainerBuilder();
    $container->setDefinition(EntityProcessingHandler::class, (new Definition(EntityProcessingHandler::class))->addTag('mediator.handler'));
    $container->setDefinition(LoadAndSaveAspect::class, (new Definition(LoadAndSaveAspect::class))->addTag('mediator.aspect'));

    new MediatorCompilerPass($testOutputDir, 'CompiledMediatorParamProviding0')->process($container);

    $locatorServices = $container->getDefinition(MediatorInterface::class)->getArgument(0)->getValues();

    expect($locatorServices)
        ->toHaveKey(EntityProcessingHandler::class)
        ->toHaveKey(LoadAndSaveAspect::class)
        // TestEntity is a claimed dep — the aspect provides it, so it must NOT be in the locator
        ->not->toHaveKey(TestEntity::class);
});

test('generated mediator uses parameter-providing aspect to inject and save entity', function () use ($testOutputDir) {
    $container = new ContainerBuilder();
    $container->setDefinition(EntityProcessingHandler::class, (new Definition(EntityProcessingHandler::class))->addTag('mediator.handler'));
    $container->setDefinition(LoadAndSaveAspect::class, (new Definition(LoadAndSaveAspect::class))->addTag('mediator.aspect'));

    $container->addCompilerPass(new MediatorCompilerPass($testOutputDir, 'CompiledMediatorParamProviding1'));
    $container->compile();

    require_once $testOutputDir . '/CompiledMediatorParamProviding1.php';

    $repo = new TestEntityRepository();
    $repoLocator = new ServiceLocator([TestEntityRepository::class => fn() => $repo]);
    $aspect = new LoadAndSaveAspect($repoLocator);

    // TestEntity is NOT in the mediator locator — LoadAndSaveAspect provides it
    $locator = new ServiceLocator([
        EntityProcessingHandler::class => fn() => new EntityProcessingHandler(),
        LoadAndSaveAspect::class => fn() => $aspect,
    ]);

    $mediator = new \CompiledMediatorParamProviding1($locator);
    $result = $mediator->send(new ProcessEntityMessage('entity-123'));

    expect($result)->toBe('entity-123')
        ->and($repo->saved)->toHaveKey('entity-123')
        ->and($repo->saved['entity-123']->processed)->toBeTrue();
});

test('one parameter-providing aspect can claim multiple handler parameters at once', function () use ($testOutputDir) {
    $container = new ContainerBuilder();
    $container->setDefinition(MultiEntityHandler::class, (new Definition(MultiEntityHandler::class))->addTag('mediator.handler'));
    $container->setDefinition(BatchLoadAspect::class, (new Definition(BatchLoadAspect::class))->addTag('mediator.aspect'));

    $container->addCompilerPass(new MediatorCompilerPass($testOutputDir, 'CompiledMediatorMultiParam1'));
    $container->compile();

    require_once $testOutputDir . '/CompiledMediatorMultiParam1.php';

    $repoLocator = new ServiceLocator([
        TestEntityARepository::class => fn() => new TestEntityARepository(),
        TestEntityBRepository::class => fn() => new TestEntityBRepository(),
    ]);
    $aspect = new BatchLoadAspect($repoLocator);

    $locator = new ServiceLocator([
        MultiEntityHandler::class => fn() => new MultiEntityHandler(),
        BatchLoadAspect::class => fn() => $aspect,
    ]);

    $mediator = new \CompiledMediatorMultiParam1($locator);
    $result = $mediator->send(new MultiEntityMessage('a-1', 'b-2'));

    expect($result)->toBe('a-1+b-2');
});

test('two #[Around] on the same aspect method each claim a different parameter and compose via arrow-function capture', function () use ($testOutputDir) {
    $container = new ContainerBuilder();
    $container->setDefinition(SeparateEntitiesHandler::class, (new Definition(SeparateEntitiesHandler::class))->addTag('mediator.handler'));
    // A single LoadAspect service produces two separate advices — one per #[Around] attribute.
    // Each call receives a different $config; one locator entry serves both.
    $container->setDefinition(LoadAspect::class, (new Definition(LoadAspect::class))->addTag('mediator.aspect'));

    $container->addCompilerPass(new MediatorCompilerPass($testOutputDir, 'CompiledMediatorMultiParam2'));
    $container->compile();

    require_once $testOutputDir . '/CompiledMediatorMultiParam2.php';

    $repoLocator = new ServiceLocator([
        TestEntityARepository::class => fn() => new TestEntityARepository(),
        TestEntityBRepository::class => fn() => new TestEntityBRepository(),
    ]);
    $singleAspect = new LoadAspect($repoLocator);

    // Neither EntityA nor EntityB is in the mediator locator — LoadAspect provides both
    $locator = new ServiceLocator([
        SeparateEntitiesHandler::class => fn() => new SeparateEntitiesHandler(),
        LoadAspect::class => fn() => $singleAspect,
    ]);

    $mediator = new \CompiledMediatorMultiParam2($locator);
    $result = $mediator->send(new SeparateEntitiesMessage('a-1', 'b-2'));

    // EntityA from priority-10 advice (outer), EntityB from priority-0 advice (inner);
    // arrow-function capture propagates $_p0 inward without an explicit use clause.
    expect($result)->toBe('a-1+b-2');
});

test('#[Around] closure discovers #[Entity] parameters at compile time and delegates to a single EntityManager at runtime', function () use ($testOutputDir) {
    $container = new ContainerBuilder();
    $container->setDefinition(EntityManagerHandler::class, (new Definition(EntityManagerHandler::class))->addTag('mediator.handler'));
    $container->setDefinition(EntityManagerAspect::class, (new Definition(EntityManagerAspect::class))->addTag('mediator.aspect'));

    $container->addCompilerPass(new MediatorCompilerPass($testOutputDir, 'CompiledMediatorEm'));
    $container->compile();

    require_once $testOutputDir . '/CompiledMediatorEm.php';

    $em = new TestEntityManager();
    $aspect = new EntityManagerAspect($em);

    // Neither EntityA nor EntityB is in the mediator locator — EntityManagerAspect provides both
    $locator = new ServiceLocator([
        EntityManagerHandler::class => fn() => new EntityManagerHandler(),
        EntityManagerAspect::class => fn() => $aspect,
    ]);

    $mediator = new \CompiledMediatorEm($locator);
    $result = $mediator->send(new ProcessEntitiesMessage('a-1', 'b-2'));

    expect($result)->toBe('a-1+b-2')
        ->and($em->findCalls)->toHaveCount(2)
        ->and($em->findCalls[0])->toBe(['class' => EntityA::class, 'id' => 'a-1'])
        ->and($em->findCalls[1])->toBe(['class' => EntityB::class, 'id' => 'b-2'])
        ->and($em->saved)->toHaveKey(EntityA::class)
        ->and($em->saved)->toHaveKey(EntityB::class);
});

// ---------------------------------------------------------------------------
// After-type advice fixtures
// ---------------------------------------------------------------------------

final readonly class ObservedMessage
{
    public function __construct(public string $payload)
    {
    }
}

final readonly class FailingMessage
{
}

final class ObservedHandler
{
    #[Handles]
    public function handle(ObservedMessage $msg): string
    {
        return 'observed: ' . $msg->payload;
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
    public function inspect(object $message, array $context, mixed $returnValue): void
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
    public function record(object $message, array $context, \Throwable $e): void
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
    public function cleanup(object $message, array $context): void
    {
        $this->calls++;
    }
}

// ---------------------------------------------------------------------------
// After-type advice tests
// ---------------------------------------------------------------------------

test('AfterReturning advice receives the handler return value', function () use ($testOutputDir) {
    $container = new ContainerBuilder();
    $container->setDefinition(ObservedHandler::class, (new Definition(ObservedHandler::class))->addTag('mediator.handler'));
    $container->setDefinition(ResultInspector::class, (new Definition(ResultInspector::class))->addTag('mediator.aspect'));

    $container->addCompilerPass(new MediatorCompilerPass($testOutputDir, 'CompiledMediatorAfterReturning1'));
    $container->compile();

    require_once $testOutputDir . '/CompiledMediatorAfterReturning1.php';

    $inspector = new ResultInspector();
    $locator = new ServiceLocator([
        ObservedHandler::class => fn() => new ObservedHandler(),
        ResultInspector::class => fn() => $inspector,
    ]);

    $mediator = new \CompiledMediatorAfterReturning1($locator);
    $result = $mediator->send(new ObservedMessage('hello'));

    expect($result)->toBe('observed: hello')
        ->and($inspector->captured)->toBe('observed: hello');
});

test('AfterThrowing advice is called when handler throws and exception still propagates', function () use ($testOutputDir) {
    $container = new ContainerBuilder();
    $container->setDefinition(FailingHandler::class, (new Definition(FailingHandler::class))->addTag('mediator.handler'));
    $container->setDefinition(ErrorRecorder::class, (new Definition(ErrorRecorder::class))->addTag('mediator.aspect'));

    $container->addCompilerPass(new MediatorCompilerPass($testOutputDir, 'CompiledMediatorAfterThrowing1'));
    $container->compile();

    require_once $testOutputDir . '/CompiledMediatorAfterThrowing1.php';

    $recorder = new ErrorRecorder();
    $locator = new ServiceLocator([
        FailingHandler::class => fn() => new FailingHandler(),
        ErrorRecorder::class => fn() => $recorder,
    ]);

    $mediator = new \CompiledMediatorAfterThrowing1($locator);

    expect(fn() => $mediator->send(new FailingMessage()))->toThrow(\RuntimeException::class, 'handler failed')
        ->and($recorder->caught)->toBeInstanceOf(\RuntimeException::class);
});

test('After advice runs after a successful handler invocation', function () use ($testOutputDir) {
    $container = new ContainerBuilder();
    $container->setDefinition(ObservedHandler::class, (new Definition(ObservedHandler::class))->addTag('mediator.handler'));
    $container->setDefinition(Cleaner::class, (new Definition(Cleaner::class))->addTag('mediator.aspect'));

    $container->addCompilerPass(new MediatorCompilerPass($testOutputDir, 'CompiledMediatorAfter1'));
    $container->compile();

    require_once $testOutputDir . '/CompiledMediatorAfter1.php';

    $cleaner = new Cleaner();
    $locator = new ServiceLocator([
        ObservedHandler::class => fn() => new ObservedHandler(),
        Cleaner::class => fn() => $cleaner,
    ]);

    $mediator = new \CompiledMediatorAfter1($locator);
    $result = $mediator->send(new ObservedMessage('world'));

    expect($result)->toBe('observed: world')
        ->and($cleaner->calls)->toBe(1);
});

test('After advice runs even when handler throws', function () use ($testOutputDir) {
    $container = new ContainerBuilder();
    $container->setDefinition(FailingHandler::class, (new Definition(FailingHandler::class))->addTag('mediator.handler'));
    $container->setDefinition(Cleaner::class, (new Definition(Cleaner::class))->addTag('mediator.aspect'));

    $container->addCompilerPass(new MediatorCompilerPass($testOutputDir, 'CompiledMediatorAfter2'));
    $container->compile();

    require_once $testOutputDir . '/CompiledMediatorAfter2.php';

    $cleaner = new Cleaner();
    $locator = new ServiceLocator([
        FailingHandler::class => fn() => new FailingHandler(),
        Cleaner::class => fn() => $cleaner,
    ]);

    $mediator = new \CompiledMediatorAfter2($locator);

    expect(fn() => $mediator->send(new FailingMessage()))->toThrow(\RuntimeException::class)
        ->and($cleaner->calls)->toBe(1);
});
