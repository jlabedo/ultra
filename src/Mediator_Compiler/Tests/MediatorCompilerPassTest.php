<?php

declare(strict_types=1);

namespace Ultra\Mediator_Compiler\Tests;

use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Definition;
use Symfony\Component\DependencyInjection\ServiceLocator;
use Symfony\Contracts\Service\ServiceProviderInterface;
use Ultra\Mediator\Attribute\AdviceDescriptor;
use Ultra\Mediator\Attribute\AspectAttribute;
use Ultra\Mediator\Attribute\Handles;
use Ultra\Mediator\Attribute\ParameterProvidingAspect;
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
// Service fixtures (plain collaborators and aspect services)
// ---------------------------------------------------------------------------

final class GreeterService
{
    public function greet(string $name): string
    {
        return 'Hello, ' . $name . '!';
    }
}

final class TestLogger
{
    /** @var list<object> */
    public array $logged = [];

    public function log(object $message, array $context): void
    {
        $this->logged[] = $message;
    }
}

final class TestTransactionManager
{
    public int $calls = 0;

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

    public function wrap(callable $proceed, object $message, array $context): mixed
    {
        $this->log[] = 'inner-before';
        $result = $proceed($message, $context);
        $this->log[] = 'inner-after';
        return $result;
    }
}

// ---------------------------------------------------------------------------
// Aspect-attribute fixtures
// ---------------------------------------------------------------------------

#[\Attribute(\Attribute::TARGET_METHOD)]
final class LogBefore implements AspectAttribute
{
    public function getAdviceDescriptor(): AdviceDescriptor
    {
        return AdviceDescriptor::before(TestLogger::class, 'log');
    }
}

#[\Attribute(\Attribute::TARGET_METHOD)]
final class Transactional implements AspectAttribute
{
    public function getAdviceDescriptor(): AdviceDescriptor
    {
        return AdviceDescriptor::around(TestTransactionManager::class, 'execute');
    }
}

#[\Attribute(\Attribute::TARGET_METHOD)]
final class OuterAspect implements AspectAttribute
{
    public function getAdviceDescriptor(): AdviceDescriptor
    {
        return AdviceDescriptor::around(OuterAspectService::class, 'wrap');
    }
}

#[\Attribute(\Attribute::TARGET_METHOD)]
final class InnerAspect implements AspectAttribute
{
    public function getAdviceDescriptor(): AdviceDescriptor
    {
        return AdviceDescriptor::around(InnerAspectService::class, 'wrap');
    }
}

// ---------------------------------------------------------------------------
// Handler fixtures
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
    #[LogBefore]
    public function handle(AuditedMessage $msg): string
    {
        return 'handled: ' . $msg->payload;
    }
}

final class WrappedHandler
{
    #[Handles]
    #[Transactional]
    public function handle(WrappedMessage $msg): string
    {
        return 'wrapped: ' . $msg->payload;
    }
}

final class DoubleWrappedHandler
{
    #[Handles]
    #[OuterAspect]
    #[InnerAspect]
    public function handle(DoubleWrappedMessage $msg): string
    {
        return 'double: ' . $msg->payload;
    }
}

// ---------------------------------------------------------------------------
// ParameterProvidingAspect fixtures
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
 * Generic aspect: loads one entity via $config['repoClass'], passes it to $proceed,
 * then saves it — all without knowing the entity type at compile time.
 */
final class LoadAndSaveAspect
{
    public function __construct(private readonly ServiceProviderInterface $locator)
    {
    }

    public function execute(callable $proceed, object $message, array $context, array $config): mixed
    {
        $repo   = $this->locator->get($config['repoClass']);
        $entity = $repo->load($message);
        $result = $proceed($message, $context, $entity);
        $repo->save($entity);
        return $result;
    }
}

/**
 * Generic aspect: loads one entity via $config['repoClass'] and passes it to $proceed.
 * No save — for read-only or externally-committed scenarios.
 */
final class LoadAspect
{
    public function __construct(private readonly ServiceProviderInterface $locator)
    {
    }

    public function execute(callable $proceed, object $message, array $context, array $config): mixed
    {
        $entity = $this->locator->get($config['repoClass'])->load($message);
        return $proceed($message, $context, $entity);
    }
}

/**
 * Generic aspect: loads multiple entities, one per repo in $config['repos'],
 * and passes them all to $proceed via splat.
 */
final class BatchLoadAspect
{
    public function __construct(private readonly ServiceProviderInterface $locator)
    {
    }

    public function execute(callable $proceed, object $message, array $context, array $config): mixed
    {
        $entities = array_map(
            fn(string $repoClass) => $this->locator->get($repoClass)->load($message),
            $config['repos'],
        );
        return $proceed($message, $context, ...$entities);
    }
}

/**
 * Aspect attribute — claims the TestEntity parameter and embeds the repository class
 * in runtimeConfig so the generic LoadAndSaveAspect can resolve it without hardcoding.
 */
#[\Attribute(\Attribute::TARGET_METHOD)]
final class WithEntity implements ParameterProvidingAspect
{
    public function __construct(private readonly string $repositoryClass)
    {
    }

    public function getAdviceDescriptor(): AdviceDescriptor
    {
        return AdviceDescriptor::around(LoadAndSaveAspect::class, 'execute');
    }

    public function compileParameterResolution(array $depParams): array
    {
        $indices = [];
        foreach ($depParams as $i => $param) {
            $type = $param->getType();
            if ($type instanceof \ReflectionNamedType && $type->getName() === TestEntity::class) {
                $indices[] = $i;
            }
        }
        return ['indices' => $indices, 'config' => ['repoClass' => $this->repositoryClass]];
    }
}

final class EntityProcessingHandler
{
    #[Handles]
    #[WithEntity(TestEntityRepository::class)]
    public function process(ProcessEntityMessage $_msg, TestEntity $entity): string
    {
        $entity->process();
        return $entity->id;
    }
}

// ---------------------------------------------------------------------------
// Entity marker attribute — applied to domain entity classes so that the
// generic #[LoadAndSave] aspect attribute can discover them at compile time.
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

/** Repository for EntityA — knows how to extract the ID from a MultiEntityMessage or SeparateEntitiesMessage */
final class TestEntityARepository
{
    public function load(object $message): EntityA
    {
        return new EntityA($message->aId);
    }
}

/** Repository for EntityB — knows how to extract the ID from a MultiEntityMessage or SeparateEntitiesMessage */
final class TestEntityBRepository
{
    public function load(object $message): EntityB
    {
        return new EntityB($message->bId);
    }
}

/**
 * Claims both EntityA and EntityB parameters; delegates to BatchLoadAspect with
 * the two repository classes embedded in runtimeConfig.
 */
#[\Attribute(\Attribute::TARGET_METHOD)]
final class WithMultiEntity implements ParameterProvidingAspect
{
    public function getAdviceDescriptor(): AdviceDescriptor
    {
        return AdviceDescriptor::around(BatchLoadAspect::class, 'execute');
    }

    public function compileParameterResolution(array $depParams): array
    {
        $indices = [];
        foreach ($depParams as $i => $param) {
            $type = $param->getType();
            if ($type instanceof \ReflectionNamedType
                && in_array($type->getName(), [EntityA::class, EntityB::class], true)) {
                $indices[] = $i;
            }
        }
        return ['indices' => $indices, 'config' => ['repos' => [TestEntityARepository::class, TestEntityBRepository::class]]];
    }
}

/** Claims EntityA only; the generic LoadAspect resolves it via $config['repoClass'] at runtime. */
#[\Attribute(\Attribute::TARGET_METHOD)]
final class WithEntityA implements ParameterProvidingAspect
{
    public function getAdviceDescriptor(): AdviceDescriptor
    {
        return AdviceDescriptor::around(LoadAspect::class, 'execute');
    }

    public function compileParameterResolution(array $depParams): array
    {
        $indices = [];
        foreach ($depParams as $i => $param) {
            $type = $param->getType();
            if ($type instanceof \ReflectionNamedType && $type->getName() === EntityA::class) {
                $indices[] = $i;
            }
        }
        return ['indices' => $indices, 'config' => ['repoClass' => TestEntityARepository::class]];
    }
}

/** Claims EntityB only; the generic LoadAspect resolves it via $config['repoClass'] at runtime. */
#[\Attribute(\Attribute::TARGET_METHOD)]
final class WithEntityB implements ParameterProvidingAspect
{
    public function getAdviceDescriptor(): AdviceDescriptor
    {
        return AdviceDescriptor::around(LoadAspect::class, 'execute');
    }

    public function compileParameterResolution(array $depParams): array
    {
        $indices = [];
        foreach ($depParams as $i => $param) {
            $type = $param->getType();
            if ($type instanceof \ReflectionNamedType && $type->getName() === EntityB::class) {
                $indices[] = $i;
            }
        }
        return ['indices' => $indices, 'config' => ['repoClass' => TestEntityBRepository::class]];
    }
}

final class MultiEntityHandler
{
    #[Handles]
    #[WithMultiEntity]
    public function handle(MultiEntityMessage $_msg, EntityA $a, EntityB $b): string
    {
        return $a->id . '+' . $b->id;
    }
}

final class SeparateEntitiesHandler
{
    #[Handles]
    #[WithEntityA]
    #[WithEntityB]
    public function handle(SeparateEntitiesMessage $_msg, EntityA $a, EntityB $b): string
    {
        return $a->id . '+' . $b->id;
    }
}

// ---------------------------------------------------------------------------
// EntityManager-based aspect fixtures
//
// A single EntityManager (à la Doctrine) replaces per-entity repositories.
// The #[LoadAndSave] attribute scans handler dep-params for classes marked
// with #[Entity], embeds their class names + idFrom fields in runtimeConfig,
// and the EntityManagerAspect uses them at runtime to load and save.
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
 * $config['entities'], passes them all to $proceed, then saves them.
 */
final class EntityManagerAspect
{
    public function __construct(private readonly EntityManagerInterface $em)
    {
    }

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

/**
 * Compile-time attribute: auto-discovers which handler parameters are entities
 * by checking for the #[Entity] attribute on their type class, then embeds
 * ['class' => ..., 'idFrom' => ...] for each in runtimeConfig.
 */
#[\Attribute(\Attribute::TARGET_METHOD)]
final class LoadAndSave implements ParameterProvidingAspect
{
    public function getAdviceDescriptor(): AdviceDescriptor
    {
        return AdviceDescriptor::around(EntityManagerAspect::class, 'execute');
    }

    public function compileParameterResolution(array $depParams): array
    {
        $indices  = [];
        $entities = [];

        foreach ($depParams as $i => $param) {
            $type = $param->getType();
            if (!$type instanceof \ReflectionNamedType || $type->isBuiltin()) {
                continue;
            }
            $refAttrs = (new \ReflectionClass($type->getName()))->getAttributes(Entity::class);
            if ($refAttrs === []) {
                continue;
            }
            $entityAttr = $refAttrs[0]->newInstance();
            $indices[]  = $i;
            $entities[] = ['class' => $type->getName(), 'idFrom' => $entityAttr->idFrom];
        }

        return ['indices' => $indices, 'config' => ['entities' => $entities]];
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
    #[LoadAndSave]
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

    $pass = new MediatorCompilerPass($testOutputDir, 'CompiledMediatorPassTest1');
    $pass->process($container);

    expect($container->hasDefinition(MediatorInterface::class))->toBeTrue();
    expect($container->hasDefinition('ultra.mediator.locator'))->toBeTrue();
});

test('compiler pass does nothing when no handlers are tagged', function () use ($testOutputDir) {
    $container = new ContainerBuilder();

    $pass = new MediatorCompilerPass($testOutputDir, 'CompiledMediatorPassTest2');
    $pass->process($container);

    expect($container->hasDefinition(MediatorInterface::class))->toBeFalse();
});

test('compiler pass throws on duplicate handlers for the same message', function () use ($testOutputDir) {
    $container = new ContainerBuilder();
    $container->setDefinition('handler_a', (new Definition(SalutationHandler::class))->addTag('mediator.handler'));
    $container->setDefinition('handler_b', (new Definition(SalutationHandler::class))->addTag('mediator.handler'));

    $pass = new MediatorCompilerPass($testOutputDir, 'CompiledMediatorPassTest3');

    expect(fn() => $pass->process($container))->toThrow(\LogicException::class);
});

test('compiler pass adds aspect service IDs to the service locator', function () use ($testOutputDir) {
    $container = new ContainerBuilder();
    $container->setDefinition(AuditedHandler::class, (new Definition(AuditedHandler::class))->addTag('mediator.handler'));
    $container->setDefinition(TestLogger::class, new Definition(TestLogger::class));

    $pass = new MediatorCompilerPass($testOutputDir, 'CompiledMediatorPassAop1');
    $pass->process($container);

    $locatorArgs = $container->getDefinition('ultra.mediator.locator')->getArgument(0);

    expect($locatorArgs)
        ->toHaveKey(AuditedHandler::class)
        ->toHaveKey(TestLogger::class);
});

// ---------------------------------------------------------------------------
// End-to-end: generated class actually dispatches correctly
// ---------------------------------------------------------------------------

test('generated mediator routes messages to the correct handler method', function () use ($testOutputDir) {
    $container = new ContainerBuilder();
    $container->setDefinition(SalutationHandler::class, (new Definition(SalutationHandler::class))->addTag('mediator.handler'));

    $pass = new MediatorCompilerPass($testOutputDir, 'CompiledMediatorE2E1');
    $pass->process($container);

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

    $pass = new MediatorCompilerPass($testOutputDir, 'CompiledMediatorE2E2');
    $pass->process($container);

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

    $pass = new MediatorCompilerPass($testOutputDir, 'CompiledMediatorE2E3');
    $pass->process($container);

    require_once $testOutputDir . '/CompiledMediatorE2E3.php';

    $locator = new ServiceLocator([
        SalutationHandler::class => fn() => new SalutationHandler(),
    ]);
    $mediator = new \CompiledMediatorE2E3($locator);

    expect(fn() => $mediator->send(new PingMessage()))
        ->toThrow(\Ultra\Mediator\Exception\NoHandlerFound::class);
});

test('generated mediator calls before-advice then invokes the handler', function () use ($testOutputDir) {
    $container = new ContainerBuilder();
    $container->setDefinition(AuditedHandler::class, (new Definition(AuditedHandler::class))->addTag('mediator.handler'));
    $container->setDefinition(TestLogger::class, new Definition(TestLogger::class));

    $pass = new MediatorCompilerPass($testOutputDir, 'CompiledMediatorAop1');
    $pass->process($container);

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
    $container->setDefinition(TestTransactionManager::class, new Definition(TestTransactionManager::class));

    $pass = new MediatorCompilerPass($testOutputDir, 'CompiledMediatorAop2');
    $pass->process($container);

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

test('compiler pass excludes claimed dep types from the service locator', function () use ($testOutputDir) {
    $container = new ContainerBuilder();
    $container->setDefinition(EntityProcessingHandler::class, (new Definition(EntityProcessingHandler::class))->addTag('mediator.handler'));
    $container->setDefinition(LoadAndSaveAspect::class, new Definition(LoadAndSaveAspect::class));

    $pass = new MediatorCompilerPass($testOutputDir, 'CompiledMediatorParamProviding0');
    $pass->process($container);

    $locatorArgs = $container->getDefinition('ultra.mediator.locator')->getArgument(0);

    expect($locatorArgs)
        ->toHaveKey(EntityProcessingHandler::class)
        ->toHaveKey(LoadAndSaveAspect::class)
        // TestEntity is a claimed dep — the aspect provides it, so it must NOT be in the locator
        ->not->toHaveKey(TestEntity::class);
});

test('generated mediator uses parameter-providing aspect to inject and save entity', function () use ($testOutputDir) {
    $container = new ContainerBuilder();
    $container->setDefinition(EntityProcessingHandler::class, (new Definition(EntityProcessingHandler::class))->addTag('mediator.handler'));
    $container->setDefinition(LoadAndSaveAspect::class, new Definition(LoadAndSaveAspect::class));

    $pass = new MediatorCompilerPass($testOutputDir, 'CompiledMediatorParamProviding1');
    $pass->process($container);

    require_once $testOutputDir . '/CompiledMediatorParamProviding1.php';

    $repo        = new TestEntityRepository();
    $repoLocator = new ServiceLocator([TestEntityRepository::class => fn() => $repo]);
    $aspect      = new LoadAndSaveAspect($repoLocator);

    // TestEntity is NOT in the mediator locator — LoadAndSaveAspect resolves it via its own repo locator
    $locator = new ServiceLocator([
        EntityProcessingHandler::class => fn() => new EntityProcessingHandler(),
        LoadAndSaveAspect::class       => fn() => $aspect,
    ]);

    $mediator = new \CompiledMediatorParamProviding1($locator);
    $result   = $mediator->send(new ProcessEntityMessage('entity-123'));

    expect($result)->toBe('entity-123')
        ->and($repo->saved)->toHaveKey('entity-123')
        ->and($repo->saved['entity-123']->processed)->toBeTrue();
});

test('generated mediator executes multiple around-advices in declaration order', function () use ($testOutputDir) {
    $container = new ContainerBuilder();
    $container->setDefinition(DoubleWrappedHandler::class, (new Definition(DoubleWrappedHandler::class))->addTag('mediator.handler'));
    $container->setDefinition(OuterAspectService::class, new Definition(OuterAspectService::class));
    $container->setDefinition(InnerAspectService::class, new Definition(InnerAspectService::class));

    $pass = new MediatorCompilerPass($testOutputDir, 'CompiledMediatorAop3');
    $pass->process($container);

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
        // First-declared (#[OuterAspect]) wraps everything
        ->and($outer->log)->toBe(['outer-before', 'outer-after'])
        // Second-declared (#[InnerAspect]) is nested inside outer
        ->and($inner->log)->toBe(['inner-before', 'inner-after']);
});

test('one parameter-providing aspect can claim multiple handler parameters at once', function () use ($testOutputDir) {
    $container = new ContainerBuilder();
    $container->setDefinition(MultiEntityHandler::class, (new Definition(MultiEntityHandler::class))->addTag('mediator.handler'));
    $container->setDefinition(BatchLoadAspect::class, new Definition(BatchLoadAspect::class));

    $pass = new MediatorCompilerPass($testOutputDir, 'CompiledMediatorMultiParam1');
    $pass->process($container);

    require_once $testOutputDir . '/CompiledMediatorMultiParam1.php';

    $repoLocator = new ServiceLocator([
        TestEntityARepository::class => fn() => new TestEntityARepository(),
        TestEntityBRepository::class => fn() => new TestEntityBRepository(),
    ]);
    $aspect = new BatchLoadAspect($repoLocator);

    $locator = new ServiceLocator([
        MultiEntityHandler::class => fn() => new MultiEntityHandler(),
        BatchLoadAspect::class    => fn() => $aspect,
    ]);

    $mediator = new \CompiledMediatorMultiParam1($locator);
    $result   = $mediator->send(new MultiEntityMessage('a-1', 'b-2'));

    expect($result)->toBe('a-1+b-2');
});

test('two parameter-providing aspects each claiming a different parameter compose via arrow-function capture', function () use ($testOutputDir) {
    $container = new ContainerBuilder();
    $container->setDefinition(SeparateEntitiesHandler::class, (new Definition(SeparateEntitiesHandler::class))->addTag('mediator.handler'));
    // Both #[WithEntityA] and #[WithEntityB] reference the same LoadAspect service class;
    // the compiler deduplicates — one locator entry, each call receives a different $config.
    $container->setDefinition(LoadAspect::class, new Definition(LoadAspect::class));

    $pass = new MediatorCompilerPass($testOutputDir, 'CompiledMediatorMultiParam2');
    $pass->process($container);

    require_once $testOutputDir . '/CompiledMediatorMultiParam2.php';

    $repoLocator = new ServiceLocator([
        TestEntityARepository::class => fn() => new TestEntityARepository(),
        TestEntityBRepository::class => fn() => new TestEntityBRepository(),
    ]);
    $singleAspect = new LoadAspect($repoLocator);

    // Neither EntityA nor EntityB is in the mediator locator — LoadAspect resolves both via config
    $locator = new ServiceLocator([
        SeparateEntitiesHandler::class => fn() => new SeparateEntitiesHandler(),
        LoadAspect::class              => fn() => $singleAspect,
    ]);

    $mediator = new \CompiledMediatorMultiParam2($locator);
    $result   = $mediator->send(new SeparateEntitiesMessage('a-1', 'b-2'));

    // EntityA injected by the outer #[WithEntityA] advice (config: TestEntityARepository),
    // EntityB injected by the inner #[WithEntityB] advice (config: TestEntityBRepository),
    // both via the same LoadAspect service — arrow-function capture propagates $_p0 inward.
    expect($result)->toBe('a-1+b-2');
});

test('#[LoadAndSave] discovers #[Entity] parameters at compile time and delegates to a single EntityManager at runtime', function () use ($testOutputDir) {
    $container = new ContainerBuilder();
    $container->setDefinition(EntityManagerHandler::class, (new Definition(EntityManagerHandler::class))->addTag('mediator.handler'));
    $container->setDefinition(EntityManagerAspect::class, new Definition(EntityManagerAspect::class));

    $pass = new MediatorCompilerPass($testOutputDir, 'CompiledMediatorEm');
    $pass->process($container);

    require_once $testOutputDir . '/CompiledMediatorEm.php';

    $em     = new TestEntityManager();
    $aspect = new EntityManagerAspect($em);

    // Neither EntityA nor EntityB is in the mediator locator — EntityManagerAspect provides both
    $locator = new ServiceLocator([
        EntityManagerHandler::class => fn() => new EntityManagerHandler(),
        EntityManagerAspect::class  => fn() => $aspect,
    ]);

    $mediator = new \CompiledMediatorEm($locator);
    $result   = $mediator->send(new ProcessEntitiesMessage('a-1', 'b-2'));

    expect($result)->toBe('a-1+b-2')
        // find() was called once per entity, in declaration order, with IDs extracted from the message
        ->and($em->findCalls)->toHaveCount(2)
        ->and($em->findCalls[0])->toBe(['class' => EntityA::class, 'id' => 'a-1'])
        ->and($em->findCalls[1])->toBe(['class' => EntityB::class, 'id' => 'b-2'])
        // save() was called for both entities after $proceed returned
        ->and($em->saved)->toHaveKey(EntityA::class)
        ->and($em->saved)->toHaveKey(EntityB::class);
});
