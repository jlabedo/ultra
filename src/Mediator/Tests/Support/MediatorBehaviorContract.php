<?php

declare(strict_types=1);

namespace Ultra\Mediator\Tests\Support;

use Ultra\MessageHandling\Attribute\After;
use Ultra\MessageHandling\Attribute\AfterReturning;
use Ultra\MessageHandling\Attribute\AfterThrowing;
use Ultra\MessageHandling\Attribute\Around;
use Ultra\MessageHandling\Attribute\Before;
use Ultra\MessageHandling\Attribute\Handles;
use Ultra\MessageHandling\Attribute\MatchResult;
use Ultra\MessageHandling\Attribute\SelfHandler;
use Ultra\MessageHandling\EventBusInterface;
use Ultra\MessageHandling\Exception\NoHandlerFound;
use Ultra\MessageHandling\MediatorInterface;
use Ultra\MessageHandling\MessageContext;
use Ultra\MessageHandling\MessageContextKeys;
use Ultra\MessageHandling\MessageContextPropagatorInterface;

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
        ->and($entries[1]['context'][MessageContextKeys::CAUSATION_ID])->toBe($entries[0]['context'][MessageContextKeys::MESSAGE_ID])
    ;
}

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

final class GreeterService
{
    public function greet(string $name): string
    {
        return 'Hello, ' . $name . '!';
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
                ->with(MessageContextKeys::CAUSATION_ID, 'fixed-root')
            ;
        }

        return $context
            ->with(MessageContextKeys::MESSAGE_ID, 'fixed-root')
            ->with(MessageContextKeys::CORRELATION_ID, 'fixed-correlation')
        ;
    }
}

final class TestLogger
{
    /** @var list<object> */
    public array $logged = [];

    #[Before(static function (\ReflectionMethod $method): ?MatchResult {
        return MatchResult::apply();
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

    #[Before(static function (\ReflectionMethod $method): ?MatchResult {
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

    #[Around(static function (\ReflectionMethod $method): ?MatchResult {
        return MatchResult::apply();
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

    #[Around(static function (\ReflectionMethod $method): ?MatchResult {
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

    #[Around(static function (\ReflectionMethod $method): ?MatchResult {
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

final class SalutationHandler
{
    #[Handles]
    public function hello(HelloMessage $message): string
    {
        return 'Hi, ' . $message->name;
    }

    #[Handles]
    public function goodbye(GoodbyeMessage $message): string
    {
        return 'Bye, ' . $message->name;
    }
}

final class GreetWithServiceHandler
{
    #[Handles]
    public function ping(PingMessage $message, GreeterService $greeter): string
    {
        return $greeter->greet('pong');
    }
}

final class AuditedHandler
{
    #[Handles]
    public function handle(AuditedMessage $message): string
    {
        return 'handled: ' . $message->payload;
    }
}

final class WrappedHandler
{
    #[Handles]
    public function handle(WrappedMessage $message): string
    {
        return 'wrapped: ' . $message->payload;
    }
}

final class DoubleWrappedHandler
{
    #[Handles]
    public function handle(DoubleWrappedMessage $message): string
    {
        return 'double: ' . $message->payload;
    }
}

final class ParentHandler
{
    #[Handles]
    public function handle(ParentMessage $message, MediatorInterface $mediator): string
    {
        return $mediator->send(new ChildMessage($message->payload));
    }
}

final class ChildHandler
{
    #[Handles]
    public function handle(ChildMessage $message): string
    {
        return 'child: ' . $message->payload;
    }
}

final class EventPublishingHandler
{
    #[Handles]
    public function handle(PublishedFromMediatorMessage $message, EventBusInterface $eventBus): string
    {
        $eventBus->publish(new RecordedEvent($message->payload));

        return 'published: ' . $message->payload;
    }
}

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

final class LoadAndSaveAspect
{
    public function __construct(private readonly TestEntityRepository $repository) {}

    #[Around(static function (\ReflectionMethod $method): ?MatchResult {
        foreach (array_slice($method->getParameters(), 1) as $index => $parameter) {
            $type = $parameter->getType();
            if ($type instanceof \ReflectionNamedType && TestEntity::class === $type->getName()) {
                return MatchResult::apply(
                    config: ['repoClass' => TestEntityRepository::class],
                    provides: [$index],
                );
            }
        }

        return null;
    })]
    public function execute(callable $proceed, object $message, MessageContext $context, array $config): mixed
    {
        $entity = $this->repository->load($message);
        $result = $proceed(provided: [$entity]);
        $this->repository->save($entity);

        return $result;
    }
}

final class EntityProcessingHandler
{
    #[Handles]
    public function process(ProcessEntityMessage $message, TestEntity $entity): string
    {
        $entity->process();

        return $entity->id;
    }
}

#[\Attribute(\Attribute::TARGET_CLASS)]
final class Entity
{
    public function __construct(public readonly string $idFrom) {}
}

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

final class TestEntityARepository
{
    public function load(object $message): EntityA
    {
        return new EntityA($message->aId);
    }
}

final class TestEntityBRepository
{
    public function load(object $message): EntityB
    {
        return new EntityB($message->bId);
    }
}

final class BatchLoadAspect
{
    #[Around(static function (\ReflectionMethod $method): ?MatchResult {
        $indices = [];
        $repositories = [];

        foreach (array_slice($method->getParameters(), 1) as $index => $parameter) {
            $type = $parameter->getType();
            if (! $type instanceof \ReflectionNamedType) {
                continue;
            }

            if (EntityA::class === $type->getName()) {
                $indices[] = $index;
                $repositories[] = TestEntityARepository::class;
            } elseif (EntityB::class === $type->getName()) {
                $indices[] = $index;
                $repositories[] = TestEntityBRepository::class;
            }
        }

        return [] !== $indices
            ? MatchResult::apply(config: ['repos' => $repositories], provides: $indices)
            : null;
    })]
    public function execute(callable $proceed, object $message, MessageContext $context, array $config): mixed
    {
        $entities = array_map(
            static fn(string $repositoryClass) => new $repositoryClass()->load($message),
            $config['repos'],
        );

        return $proceed(provided: $entities);
    }
}

final class LoadAspect
{
    public function __construct(
        private readonly TestEntityARepository $entityARepository,
        private readonly TestEntityBRepository $entityBRepository,
    ) {}

    #[Around(static function (\ReflectionMethod $method): ?MatchResult {
        foreach (array_slice($method->getParameters(), 1) as $index => $parameter) {
            $type = $parameter->getType();
            if ($type instanceof \ReflectionNamedType && EntityA::class === $type->getName()) {
                return MatchResult::apply(config: ['repoClass' => TestEntityARepository::class], provides: [$index]);
            }
        }

        return null;
    }, priority: 0)]
    #[Around(static function (\ReflectionMethod $method): ?MatchResult {
        foreach (array_slice($method->getParameters(), 1) as $index => $parameter) {
            $type = $parameter->getType();
            if ($type instanceof \ReflectionNamedType && EntityB::class === $type->getName()) {
                return MatchResult::apply(config: ['repoClass' => TestEntityBRepository::class], provides: [$index]);
            }
        }

        return null;
    }, priority: 10)]
    public function execute(callable $proceed, object $message, MessageContext $context, array $config): mixed
    {
        $entity = match ($config['repoClass']) {
            TestEntityARepository::class => $this->entityARepository->load($message),
            TestEntityBRepository::class => $this->entityBRepository->load($message),
            default => throw new \InvalidArgumentException(\sprintf('Unknown repository "%s".', $config['repoClass'])),
        };

        return $proceed(provided: [$entity]);
    }
}

final class MultiEntityHandler
{
    #[Handles]
    public function handle(MultiEntityMessage $message, EntityA $entityA, EntityB $entityB): string
    {
        return $entityA->id . '+' . $entityB->id;
    }
}

final class SeparateEntitiesHandler
{
    #[Handles]
    public function handle(SeparateEntitiesMessage $message, EntityA $entityA, EntityB $entityB): string
    {
        return $entityA->id . '+' . $entityB->id;
    }
}

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

final class EntityManagerAspect
{
    public function __construct(private readonly EntityManagerInterface $entityManager) {}

    #[Around(static function (\ReflectionMethod $method): ?MatchResult {
        $indices = [];
        $entities = [];

        foreach (array_slice($method->getParameters(), 1) as $index => $parameter) {
            $type = $parameter->getType();
            if (! $type instanceof \ReflectionNamedType || $type->isBuiltin()) {
                continue;
            }

            $attributes = new \ReflectionClass($type->getName())->getAttributes(Entity::class);
            if ([] === $attributes) {
                continue;
            }

            /** @var Entity $entityAttribute */
            $entityAttribute = $attributes[0]->newInstance();
            $indices[] = $index;
            $entities[] = ['class' => $type->getName(), 'idFrom' => $entityAttribute->idFrom];
        }

        return [] !== $entities
            ? MatchResult::apply(config: ['entities' => $entities], provides: $indices)
            : null;
    })]
    public function execute(callable $proceed, object $message, MessageContext $context, array $config): mixed
    {
        $entities = [];
        foreach ($config['entities'] as ['class' => $class, 'idFrom' => $idFrom]) {
            $entities[] = $this->entityManager->find($class, $message->{$idFrom});
        }

        $result = $proceed(provided: $entities);

        foreach ($entities as $entity) {
            $this->entityManager->save($entity);
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
    public function handle(ProcessEntitiesMessage $message, EntityA $entityA, EntityB $entityB): string
    {
        return $entityA->id . '+' . $entityB->id;
    }
}

final readonly class ObservedMessage
{
    public function __construct(public string $payload) {}
}

final readonly class FailingMessage {}

final class ObservedHandler
{
    #[Handles]
    public function handle(ObservedMessage $message): string
    {
        return 'observed: ' . $message->payload;
    }
}

final class FailingHandler
{
    #[Handles]
    public function handle(FailingMessage $message): string
    {
        throw new \RuntimeException('handler failed');
    }
}

final class ResultInspector
{
    public mixed $captured = null;

    #[AfterReturning(static function (\ReflectionMethod $method): ?MatchResult {
        return MatchResult::apply();
    })]
    public function inspect(object $message, MessageContext $context, mixed $returnValue): void
    {
        $this->captured = $returnValue;
    }
}

final class ErrorRecorder
{
    public ?\Throwable $caught = null;

    #[AfterThrowing(static function (\ReflectionMethod $method): ?MatchResult {
        return MatchResult::apply();
    })]
    public function record(object $message, MessageContext $context, \Throwable $throwable): void
    {
        $this->caught = $throwable;
    }
}

final class Cleaner
{
    public int $calls = 0;

    #[After(static function (\ReflectionMethod $method): ?MatchResult {
        return MatchResult::apply();
    })]
    public function cleanup(object $message, MessageContext $context): void
    {
        ++$this->calls;
    }
}

final class SelfGreetMessage
{
    public function __construct(public readonly string $name) {}

    #[SelfHandler]
    public function greet(): string
    {
        return 'Hi, ' . $this->name . ' (self)';
    }
}

final class SelfGreetWithServiceMessage
{
    public function __construct(public readonly string $name) {}

    #[SelfHandler]
    public function greet(GreeterService $greeter): string
    {
        return $greeter->greet($this->name);
    }
}

function registerMediatorBehaviorContractTests(MediatorFactoryInterface $factory): void
{
    it('dispatches registered messages and returns their handler results', function () use ($factory) {
        $mediator = $factory->createMediator([new SalutationHandler()], [], 'DispatchesMessages');

        expect($mediator->send(new HelloMessage('Alice')))->toBe('Hi, Alice')
            ->and($mediator->send(new GoodbyeMessage('Bob')))->toBe('Bye, Bob')
        ;
    });

    it('resolves handler dependencies from services', function () use ($factory) {
        $mediator = $factory->createMediator([
            new GreetWithServiceHandler(),
            new GreeterService(),
        ], [], 'HandlerDependencies');

        expect($mediator->send(new PingMessage()))->toBe('Hello, pong!');
    });

    it('throws NoHandlerFound for unregistered messages', function () use ($factory) {
        $mediator = $factory->createMediator([new SalutationHandler()], [], 'NoHandlerFound');

        expect(fn() => $mediator->send(new PingMessage()))
            ->toThrow(NoHandlerFound::class);
    });

    it('applies before advice before invoking the handler', function () use ($factory) {
        $logger = new TestLogger();
        $mediator = $factory->createMediator([
            new AuditedHandler(),
            $logger,
        ], [], 'BeforeAdvice');
        $message = new AuditedMessage('test');

        expect($mediator->send($message))->toBe('handled: test')
            ->and($logger->logged)->toHaveCount(1)
            ->and($logger->logged[0])->toBe($message)
        ;
    });

    it('wraps handler invocation in around advice', function () use ($factory) {
        $transactionManager = new TestTransactionManager();
        $mediator = $factory->createMediator([
            new WrappedHandler(),
            $transactionManager,
        ], [], 'AroundAdvice');

        expect($mediator->send(new WrappedMessage('test')))->toBe('wrapped: test')
            ->and($transactionManager->calls)->toBe(1)
        ;
    });

    it('nests multiple around advices by priority', function () use ($factory) {
        $outer = new OuterAspectService();
        $inner = new InnerAspectService();
        $mediator = $factory->createMediator([
            new DoubleWrappedHandler(),
            $outer,
            $inner,
        ], [], 'AroundPriority');

        expect($mediator->send(new DoubleWrappedMessage('test')))->toBe('double: test')
            ->and($outer->log)->toBe(['outer-before', 'outer-after'])
            ->and($inner->log)->toBe(['inner-before', 'inner-after'])
        ;
    });

    it('propagates context through nested mediator sends', function () use ($factory) {
        $recorder = new MessageContextRecorder();
        $mediator = $factory->createMediator([
            new ParentHandler(),
            new ChildHandler(),
            $recorder,
        ], [], 'NestedSendContext');

        expect($mediator->send(new ParentMessage('nested'), ['traceId' => 'trace-456']))->toBe('child: nested');
        assertMessageFlowContexts($recorder->entries, ParentMessage::class, ChildMessage::class, 'trace-456');
    });

    it('accepts MessageContext instances at the send boundary', function () use ($factory) {
        $recorder = new MessageContextRecorder();
        $mediator = $factory->createMediator([
            new ParentHandler(),
            new ChildHandler(),
            $recorder,
        ], [], 'MessageContextInput');

        expect($mediator->send(
            new ParentMessage('typed'),
            MessageContext::coerce(['traceId' => 'trace-typed']),
        ))->toBe('child: typed');

        assertMessageFlowContexts($recorder->entries, ParentMessage::class, ChildMessage::class, 'trace-typed');
    });

    it('honors a configured message context propagator service', function () use ($factory) {
        $recorder = new MessageContextRecorder();
        $mediator = $factory->createMediator([
            new ParentHandler(),
            new ChildHandler(),
            $recorder,
            MessageContextPropagatorInterface::class => new FixedMessageContextPropagator(),
        ], [], 'CustomPropagator');

        expect($mediator->send(new ParentMessage('custom'), ['traceId' => 'trace-custom']))->toBe('child: custom')
            ->and($recorder->entries[0]['context'][MessageContextKeys::MESSAGE_ID])->toBe('fixed-root')
            ->and($recorder->entries[0]['context'][MessageContextKeys::CORRELATION_ID])->toBe('fixed-correlation')
            ->and($recorder->entries[1]['context'][MessageContextKeys::MESSAGE_ID])->toBe('fixed-child')
            ->and($recorder->entries[1]['context'][MessageContextKeys::CORRELATION_ID])->toBe('fixed-correlation')
            ->and($recorder->entries[1]['context'][MessageContextKeys::CAUSATION_ID])->toBe('fixed-root')
        ;
    });

    it('propagates context to an injected event bus', function () use ($factory) {
        $eventBus = new RecordingEventBus();
        $mediator = $factory->createMediator([
            new EventPublishingHandler(),
            EventBusInterface::class => $eventBus,
        ], [], 'EventBusContext');

        expect($mediator->send(new PublishedFromMediatorMessage('bridge'), ['traceId' => 'trace-eb']))->toBe('published: bridge')
            ->and($eventBus->calls)->toHaveCount(1)
            ->and($eventBus->calls[0]['event'])->toBeInstanceOf(RecordedEvent::class)
            ->and($eventBus->calls[0]['context']['traceId'])->toBe('trace-eb')
            ->and($eventBus->calls[0]['context'])->toHaveKey(MessageContextKeys::MESSAGE_ID)
            ->and($eventBus->calls[0]['context'])->toHaveKey(MessageContextKeys::CORRELATION_ID)
        ;
    });

    it('lets around advice provide a handler dependency', function () use ($factory) {
        $repository = new TestEntityRepository();
        $mediator = $factory->createMediator([
            new EntityProcessingHandler(),
            new LoadAndSaveAspect($repository),
            $repository,
        ], [], 'ProvidedDependency');

        expect($mediator->send(new ProcessEntityMessage('entity-123')))->toBe('entity-123')
            ->and($repository->saved)->toHaveKey('entity-123')
            ->and($repository->saved['entity-123']->processed)->toBeTrue()
        ;
    });

    it('lets one around advice provide multiple handler dependencies', function () use ($factory) {
        $mediator = $factory->createMediator([
            new MultiEntityHandler(),
            new BatchLoadAspect(),
        ], [], 'MultipleProvidedDependencies');

        expect($mediator->send(new MultiEntityMessage('a-1', 'b-2')))->toBe('a-1+b-2');
    });

    it('composes multiple around advices that each provide a dependency', function () use ($factory) {
        $entityARepository = new TestEntityARepository();
        $entityBRepository = new TestEntityBRepository();
        $mediator = $factory->createMediator([
            new SeparateEntitiesHandler(),
            new LoadAspect($entityARepository, $entityBRepository),
            $entityARepository,
            $entityBRepository,
        ], [], 'SplitProvidedDependencies');

        expect($mediator->send(new SeparateEntitiesMessage('a-1', 'b-2')))->toBe('a-1+b-2');
    });

    it('supports generic entity-loading advice discovered from handler parameter metadata', function () use ($factory) {
        $entityManager = new TestEntityManager();
        $mediator = $factory->createMediator([
            new EntityManagerHandler(),
            new EntityManagerAspect($entityManager),
        ], [], 'EntityManagerAspect');

        expect($mediator->send(new ProcessEntitiesMessage('a-1', 'b-2')))->toBe('a-1+b-2')
            ->and($entityManager->findCalls)->toHaveCount(2)
            ->and($entityManager->findCalls[0])->toBe(['class' => EntityA::class, 'id' => 'a-1'])
            ->and($entityManager->findCalls[1])->toBe(['class' => EntityB::class, 'id' => 'b-2'])
            ->and($entityManager->saved)->toHaveKey(EntityA::class)
            ->and($entityManager->saved)->toHaveKey(EntityB::class)
        ;
    });

    it('exposes handler return values to AfterReturning advice', function () use ($factory) {
        $inspector = new ResultInspector();
        $mediator = $factory->createMediator([
            new ObservedHandler(),
            $inspector,
        ], [], 'AfterReturning');

        expect($mediator->send(new ObservedMessage('hello')))->toBe('observed: hello')
            ->and($inspector->captured)->toBe('observed: hello')
        ;
    });

    it('invokes AfterThrowing advice and still rethrows the handler exception', function () use ($factory) {
        $recorder = new ErrorRecorder();
        $mediator = $factory->createMediator([
            new FailingHandler(),
            $recorder,
        ], [], 'AfterThrowing');

        expect(fn() => $mediator->send(new FailingMessage()))->toThrow(\RuntimeException::class, 'handler failed')
            ->and($recorder->caught)->toBeInstanceOf(\RuntimeException::class)
        ;
    });

    it('runs After advice after a successful handler call', function () use ($factory) {
        $cleaner = new Cleaner();
        $mediator = $factory->createMediator([
            new ObservedHandler(),
            $cleaner,
        ], [], 'AfterSuccess');

        expect($mediator->send(new ObservedMessage('world')))->toBe('observed: world')
            ->and($cleaner->calls)->toBe(1)
        ;
    });

    it('runs After advice after a failed handler call', function () use ($factory) {
        $cleaner = new Cleaner();
        $mediator = $factory->createMediator([
            new FailingHandler(),
            $cleaner,
        ], [], 'AfterFailure');

        expect(fn() => $mediator->send(new FailingMessage()))->toThrow(\RuntimeException::class)
            ->and($cleaner->calls)->toBe(1)
        ;
    });

    it('dispatches self-handling messages', function () use ($factory) {
        $mediator = $factory->createMediator([], [SelfGreetMessage::class], 'SelfHandler');

        expect($mediator->send(new SelfGreetMessage('Alice')))->toBe('Hi, Alice (self)');
    });

    it('injects services into self-handling methods', function () use ($factory) {
        $mediator = $factory->createMediator([new GreeterService()], [SelfGreetWithServiceMessage::class], 'SelfHandlerDependencies');

        expect($mediator->send(new SelfGreetWithServiceMessage('Bob')))->toBe('Hello, Bob!');
    });

    it('supports self-handlers and regular handlers in the same mediator', function () use ($factory) {
        $mediator = $factory->createMediator([new SalutationHandler()], [SelfGreetMessage::class], 'MixedHandlers');

        expect($mediator->send(new HelloMessage('Alice')))->toBe('Hi, Alice')
            ->and($mediator->send(new SelfGreetMessage('Bob')))->toBe('Hi, Bob (self)')
        ;
    });

    it('applies advice to self-handling methods', function () use ($factory) {
        $logger = new TestLogger();
        $mediator = $factory->createMediator([$logger], [SelfGreetMessage::class], 'SelfHandlerAdvice');
        $message = new SelfGreetMessage('Carol');

        expect($mediator->send($message))->toBe('Hi, Carol (self)')
            ->and($logger->logged)->toHaveCount(1)
            ->and($logger->logged[0])->toBe($message)
        ;
    });
}
