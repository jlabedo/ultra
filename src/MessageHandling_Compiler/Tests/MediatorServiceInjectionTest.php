<?php

declare(strict_types=1);

namespace Ultra\MessageHandling_Compiler\Tests;

use Ultra\MessageHandling\Attribute\Around;
use Ultra\MessageHandling\Attribute\HandlerMatchContext;
use Ultra\MessageHandling\Attribute\Handles;
use Ultra\MessageHandling\Attribute\MatchResult;
use Ultra\MessageHandling\MessageContext;
use Ultra\MessageHandling\ProceedResult;
use Ultra\MessageHandling_Compiler\Tests\Fixtures\EmptyMessage;
use Ultra\MessageHandling_Compiler\Tests\Fixtures\ProcessEntityMessage;
use Ultra\MessageHandling_Compiler\Tests\Fixtures\ProcessPairMessage;
use Ultra\MessageHandling_Compiler\Tests\Support\MessageHandlingCompilerTestCase;

final class MediatorServiceInjectionGreeter
{
    public function greet(string $name): string
    {
        return 'Hello, '.$name.'!';
    }
}

final class MediatorServiceInjectionEntity
{
    public bool $processed = false;

    public function __construct(public readonly string $id) {}

    public function process(): void
    {
        $this->processed = true;
    }
}

final class MediatorServiceInjectionEntityRepository
{
    /** @var array<string, MediatorServiceInjectionEntity> */
    public array $saved = [];

    public function load(ProcessEntityMessage $message): MediatorServiceInjectionEntity
    {
        return new MediatorServiceInjectionEntity($message->entityId);
    }

    public function save(MediatorServiceInjectionEntity $entity): void
    {
        $this->saved[$entity->id] = $entity;
    }
}

final readonly class MediatorServiceInjectionLeftEntity
{
    public function __construct(public string $id) {}
}

final readonly class MediatorServiceInjectionRightEntity
{
    public function __construct(public string $id) {}
}

final class MediatorServiceInjectionLeftRepository
{
    public function load(ProcessPairMessage $message): MediatorServiceInjectionLeftEntity
    {
        return new MediatorServiceInjectionLeftEntity($message->leftId);
    }
}

final class MediatorServiceInjectionRightRepository
{
    public function load(ProcessPairMessage $message): MediatorServiceInjectionRightEntity
    {
        return new MediatorServiceInjectionRightEntity($message->rightId);
    }
}

#[\Attribute(\Attribute::TARGET_CLASS)]
final readonly class MediatorServiceInjectionEntityConfig
{
    public function __construct(public string $idProperty) {}
}

#[MediatorServiceInjectionEntityConfig(idProperty: 'leftId')]
final readonly class MediatorServiceInjectionConfiguredLeftEntity
{
    public function __construct(public string $id) {}
}

#[MediatorServiceInjectionEntityConfig(idProperty: 'rightId')]
final readonly class MediatorServiceInjectionConfiguredRightEntity
{
    public function __construct(public string $id) {}
}

final class MediatorServiceInjectionEntityManager
{
    /** @var list<array{class: class-string, id: string}> */
    public array $findCalls = [];

    /** @var array<class-string, array<string, object>> */
    public array $saved = [];

    /**
     * @param class-string $class
     */
    public function find(string $class, string $id): object
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
 * @internal
 *
 * @coversNothing
 */
final class MediatorServiceInjectionTest extends MessageHandlingCompilerTestCase
{
    public function test_it_resolves_handler_method_dependencies_from_services(): void
    {
        $mediator = $this->ultraLight()
            ->withService(new MediatorServiceInjectionGreeter())
            ->withService(new class {
                #[Handles]
                public function handle(EmptyMessage $message, MediatorServiceInjectionGreeter $greeter): string
                {
                    return $greeter->greet('pong');
                }
            })
            ->boot()->mediator()
        ;

        self::assertSame('Hello, pong!', $mediator->send(new EmptyMessage()));
    }

    public function test_it_lets_around_advice_provide_a_handler_dependency(): void
    {
        $repository = new MediatorServiceInjectionEntityRepository();

        $mediator = $this->ultraLight()
            ->withService($repository)
            ->withService(new class {
                #[Handles]
                public function handle(ProcessEntityMessage $message, MediatorServiceInjectionEntity $entity): string
                {
                    $entity->process();

                    return $entity->id;
                }
            })
            ->withService(new class($repository) {
                public function __construct(
                    private readonly MediatorServiceInjectionEntityRepository $repository,
                ) {}

                #[Around(static function (HandlerMatchContext $ctx): ?MatchResult {
                    $method = $ctx->method();
                    foreach (array_slice($method->getParameters(), 1) as $index => $parameter) {
                        $type = $parameter->getType();
                        if (
                            $type instanceof \ReflectionNamedType
                            && MediatorServiceInjectionEntity::class === $type->getName()
                        ) {
                            return MatchResult::apply(provides: [$index]);
                        }
                    }

                    return null;
                })]
                public function execute(callable $proceed, object $message, MessageContext $context): ProceedResult
                {
                    $entity = $this->repository->load($message);
                    $result = $proceed(provided: [$entity]);
                    $this->repository->save($entity);

                    return $result;
                }
            })
            ->boot()->mediator()
        ;

        self::assertSame('entity-123', $mediator->send(new ProcessEntityMessage('entity-123')));
        self::assertArrayHasKey('entity-123', $repository->saved);
        self::assertTrue($repository->saved['entity-123']->processed);
    }

    public function test_it_lets_one_around_advice_provide_multiple_handler_dependencies(): void
    {
        $mediator = $this->ultraLight()
            ->withService(new class {
                #[Handles]
                public function handle(
                    ProcessPairMessage $message,
                    MediatorServiceInjectionLeftEntity $left,
                    MediatorServiceInjectionRightEntity $right,
                ): string {
                    return $left->id.'+'.$right->id;
                }
            })
            ->withService(new class {
                #[Around(static function (HandlerMatchContext $ctx): ?MatchResult {
                    $method = $ctx->method();
                    $indices = [];
                    foreach (array_slice($method->getParameters(), 1) as $index => $parameter) {
                        $type = $parameter->getType();
                        if (
                            $type instanceof \ReflectionNamedType
                            && in_array(
                                $type->getName(),
                                [
                                    MediatorServiceInjectionLeftEntity::class,
                                    MediatorServiceInjectionRightEntity::class,
                                ],
                                true,
                            )
                        ) {
                            $indices[] = $index;
                        }
                    }

                    return [] === $indices ? null : MatchResult::apply(provides: $indices);
                })]
                public function execute(callable $proceed, ProcessPairMessage $message, MessageContext $context): ProceedResult
                {
                    return $proceed(provided: [
                        new MediatorServiceInjectionLeftRepository()->load($message),
                        new MediatorServiceInjectionRightRepository()->load($message),
                    ]);
                }
            })
            ->boot()->mediator()
        ;

        self::assertSame('left-1+right-2', $mediator->send(new ProcessPairMessage('left-1', 'right-2')));
    }

    public function test_it_composes_multiple_around_advices_that_each_provide_one_dependency(): void
    {
        $leftRepository = new MediatorServiceInjectionLeftRepository();
        $rightRepository = new MediatorServiceInjectionRightRepository();

        $mediator = $this->ultraLight()
            ->withService($leftRepository)
            ->withService($rightRepository)
            ->withService(new class {
                #[Handles]
                public function handle(
                    ProcessPairMessage $message,
                    MediatorServiceInjectionLeftEntity $left,
                    MediatorServiceInjectionRightEntity $right,
                ): string {
                    return $left->id.'+'.$right->id;
                }
            })
            ->withService(new class($leftRepository, $rightRepository) {
                public function __construct(
                    private readonly MediatorServiceInjectionLeftRepository $leftRepository,
                    private readonly MediatorServiceInjectionRightRepository $rightRepository,
                ) {}

                #[Around(static function (HandlerMatchContext $ctx): ?MatchResult {
                    $method = $ctx->method();
                    foreach (array_slice($method->getParameters(), 1) as $index => $parameter) {
                        $type = $parameter->getType();
                        if (
                            $type instanceof \ReflectionNamedType
                            && MediatorServiceInjectionLeftEntity::class === $type->getName()
                        ) {
                            return MatchResult::apply(provides: [$index], config: ['side' => 'left']);
                        }
                    }

                    return null;
                }, priority: 0)]
                #[Around(static function (HandlerMatchContext $ctx): ?MatchResult {
                    $method = $ctx->method();
                    foreach (array_slice($method->getParameters(), 1) as $index => $parameter) {
                        $type = $parameter->getType();
                        if (
                            $type instanceof \ReflectionNamedType
                            && MediatorServiceInjectionRightEntity::class === $type->getName()
                        ) {
                            return MatchResult::apply(provides: [$index], config: ['side' => 'right']);
                        }
                    }

                    return null;
                }, priority: 10)]
                public function execute(
                    callable $proceed,
                    ProcessPairMessage $message,
                    MessageContext $context,
                    array $config,
                ): ProceedResult {
                    $entity = match ($config['side']) {
                        'left' => $this->leftRepository->load($message),
                        'right' => $this->rightRepository->load($message),
                    };

                    return $proceed(provided: [$entity]);
                }
            })
            ->boot()->mediator()
        ;

        self::assertSame('left-10+right-20', $mediator->send(new ProcessPairMessage('left-10', 'right-20')));
    }

    public function test_it_supports_generic_entity_loading_advice_based_on_handler_parameter_metadata(): void
    {
        $entityManager = new MediatorServiceInjectionEntityManager();

        $mediator = $this->ultraLight()
            ->withService($entityManager)
            ->withService(new class {
                #[Handles]
                public function handle(
                    ProcessPairMessage $message,
                    MediatorServiceInjectionConfiguredLeftEntity $left,
                    MediatorServiceInjectionConfiguredRightEntity $right,
                ): string {
                    return $left->id.'+'.$right->id;
                }
            })
            ->withService(new class($entityManager) {
                public function __construct(private readonly MediatorServiceInjectionEntityManager $entityManager) {}

                #[Around(static function (HandlerMatchContext $ctx): ?MatchResult {
                    $method = $ctx->method();
                    $indices = [];
                    $entities = [];

                    foreach (array_slice($method->getParameters(), 1) as $index => $parameter) {
                        $type = $parameter->getType();
                        if (!$type instanceof \ReflectionNamedType || $type->isBuiltin()) {
                            continue;
                        }

                        $attributes = new \ReflectionClass($type->getName())
                            ->getAttributes(MediatorServiceInjectionEntityConfig::class)
                        ;
                        if ([] === $attributes) {
                            continue;
                        }

                        $indices[] = $index;
                        $entities[] = [
                            'class' => $type->getName(),
                            'idProperty' => $attributes[0]->newInstance()->idProperty,
                        ];
                    }

                    return [] === $entities
                        ? null
                        : MatchResult::apply(provides: $indices, config: ['entities' => $entities]);
                })]
                public function execute(
                    callable $proceed,
                    ProcessPairMessage $message,
                    MessageContext $context,
                    array $config,
                ): ProceedResult {
                    $entities = [];

                    foreach ($config['entities'] as ['class' => $class, 'idProperty' => $idProperty]) {
                        $entities[] = $this->entityManager->find($class, $message->{$idProperty});
                    }

                    $result = $proceed(provided: $entities);

                    foreach ($entities as $entity) {
                        $this->entityManager->save($entity);
                    }

                    return $result;
                }
            })
            ->boot()->mediator()
        ;

        self::assertSame('left-100+right-200', $mediator->send(new ProcessPairMessage('left-100', 'right-200')));
        self::assertSame([
            ['class' => MediatorServiceInjectionConfiguredLeftEntity::class, 'id' => 'left-100'],
            ['class' => MediatorServiceInjectionConfiguredRightEntity::class, 'id' => 'right-200'],
        ], $entityManager->findCalls);
        self::assertArrayHasKey(MediatorServiceInjectionConfiguredLeftEntity::class, $entityManager->saved);
        self::assertArrayHasKey(MediatorServiceInjectionConfiguredRightEntity::class, $entityManager->saved);
    }
}
