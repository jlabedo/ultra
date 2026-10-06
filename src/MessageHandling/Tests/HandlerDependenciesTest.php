<?php

declare(strict_types=1);

namespace Ultra\MessageHandling\Tests;

use PHPUnit\Framework\Attributes\CoversNamespace;
use Ultra\MessageHandling\Attribute\Around;
use Ultra\MessageHandling\Attribute\HandlerMatchContext;
use Ultra\MessageHandling\Attribute\Handles;
use Ultra\MessageHandling\Attribute\MatchResult;
use Ultra\MessageHandling\Attribute\SelfHandler;
use Ultra\MessageHandling\MessageContext;
use Ultra\MessageHandling\ProceedResult;
use Ultra\MessageHandling\Tests\Support\ConfiguredLeftEntity;
use Ultra\MessageHandling\Tests\Support\ConfiguredRightEntity;
use Ultra\MessageHandling\Tests\Support\Entity;
use Ultra\MessageHandling\Tests\Support\EntityConfig;
use Ultra\MessageHandling\Tests\Support\EntityManager;
use Ultra\MessageHandling\Tests\Support\EntityRepository;
use Ultra\MessageHandling\Tests\Support\Greeter;
use Ultra\MessageHandling\Tests\Support\LeftEntity;
use Ultra\MessageHandling\Tests\Support\LeftRepository;
use Ultra\MessageHandling\Tests\Support\RightEntity;
use Ultra\MessageHandling\Tests\Support\RightRepository;
use Ultra\MessageHandling\Tests\Support\SelfHandlerGreeter;
use Ultra\MessageHandling_Compiler\Tests\Fixtures\EmptyMessage;
use Ultra\MessageHandling_Compiler\Tests\Fixtures\ProcessEntityMessage;
use Ultra\MessageHandling_Compiler\Tests\Fixtures\ProcessPairMessage;
use Ultra\MessageHandling_Compiler\Tests\Support\MessageHandlingCompilerTestCase;

require_once __DIR__.'/Support/HandlerFixtures.php';

#[CoversNamespace('Ultra\\MessageHandling')]
#[CoversNamespace('Ultra\\MessageHandling_Compiler')]
#[CoversNamespace('Ultra\\MessageHandling_PostgresQueue')]
final class HandlerDependenciesTest extends MessageHandlingCompilerTestCase
{
    public function test_it_resolves_handler_method_dependencies_from_services(): void
    {
        $mediator = $this->ultraLight()
            ->withService(new Greeter())
            ->withService(new class {
                #[Handles]
                public function handle(EmptyMessage $message, Greeter $greeter): string
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
        $repository = new EntityRepository();

        $mediator = $this->ultraLight()
            ->withService($repository)
            ->withService(new class {
                #[Handles]
                public function handle(ProcessEntityMessage $message, Entity $__result): string
                {
                    $__result->process();

                    return $__result->id;
                }
            })
            ->withService(new class($repository) {
                public function __construct(
                    private readonly EntityRepository $repository,
                ) {}

                #[Around(static function (HandlerMatchContext $ctx): ?MatchResult {
                    $method = $ctx->method();
                    foreach (array_slice($method->getParameters(), 1) as $index => $parameter) {
                        $type = $parameter->getType();
                        if (
                            $type instanceof \ReflectionNamedType
                            && Entity::class === $type->getName()
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
                    LeftEntity $left,
                    RightEntity $right,
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
                                    LeftEntity::class,
                                    RightEntity::class,
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
                        new LeftRepository()->load($message),
                        new RightRepository()->load($message),
                    ]);
                }
            })
            ->boot()->mediator()
        ;

        self::assertSame('left-1+right-2', $mediator->send(new ProcessPairMessage('left-1', 'right-2')));
    }

    public function test_it_composes_multiple_around_advices_that_each_provide_one_dependency(): void
    {
        $leftRepository = new LeftRepository();
        $rightRepository = new RightRepository();

        $mediator = $this->ultraLight()
            ->withService($leftRepository)
            ->withService($rightRepository)
            ->withService(new class {
                #[Handles]
                public function handle(
                    ProcessPairMessage $message,
                    LeftEntity $left,
                    RightEntity $right,
                ): string {
                    return $left->id.'+'.$right->id;
                }
            })
            ->withService(new class($leftRepository, $rightRepository) {
                public function __construct(
                    private readonly LeftRepository $leftRepository,
                    private readonly RightRepository $rightRepository,
                ) {}

                #[Around(static function (HandlerMatchContext $ctx): ?MatchResult {
                    $method = $ctx->method();
                    foreach (array_slice($method->getParameters(), 1) as $index => $parameter) {
                        $type = $parameter->getType();
                        if (
                            $type instanceof \ReflectionNamedType
                            && LeftEntity::class === $type->getName()
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
                            && RightEntity::class === $type->getName()
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
        $entityManager = new EntityManager();

        $mediator = $this->ultraLight()
            ->withService($entityManager)
            ->withService(new class {
                #[Handles]
                public function handle(
                    ProcessPairMessage $message,
                    ConfiguredLeftEntity $left,
                    ConfiguredRightEntity $right,
                ): string {
                    return $left->id.'+'.$right->id;
                }
            })
            ->withService(new class($entityManager) {
                public function __construct(private readonly EntityManager $entityManager) {}

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
                            ->getAttributes(EntityConfig::class)
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
            ['class' => ConfiguredLeftEntity::class, 'id' => 'left-100'],
            ['class' => ConfiguredRightEntity::class, 'id' => 'right-200'],
        ], $entityManager->findCalls);
        self::assertArrayHasKey(ConfiguredLeftEntity::class, $entityManager->saved);
        self::assertArrayHasKey(ConfiguredRightEntity::class, $entityManager->saved);
    }

    public function test_it_injects_services_into_self_handling_methods(): void
    {
        $message = new readonly class('Bob') {
            public function __construct(public string $name) {}

            #[SelfHandler]
            public function greet(SelfHandlerGreeter $greeter): string
            {
                return $greeter->greet($this->name);
            }
        };
        $mediator = $this->ultraLight()
            ->withClass($message::class, SelfHandlerGreeter::class)
            ->boot()->mediator()
        ;

        self::assertSame('Hello, Bob!', $mediator->send($message));
    }
}
