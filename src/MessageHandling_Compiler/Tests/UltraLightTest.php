<?php

declare(strict_types=1);

namespace Ultra\MessageHandling_Compiler\Tests;

use Symfony\Component\DependencyInjection\Container;
use Ultra\MessageHandling\Attribute\Handles;
use Ultra\MessageHandling\MediatorInterface;
use Ultra\MessageHandling_Compiler\Tests\Fixtures\GreetingMessage;
use Ultra\MessageHandling_Compiler\Tests\Support\MessageHandlingCompilerTestCase;
use Ultra\MessageHandling_Compiler\Tests\Support\UltraLightContainer;
use Ultra\MessageHandling_Compiler\Tests\Support\UltraLightContainerFactoryInterface;

final readonly class UltraLightTestContainer extends UltraLightContainer
{
    public function typedMediator(): MediatorInterface
    {
        return $this->mediator();
    }
}

/**
 * @internal
 *
 * @coversNothing
 */
final class UltraLightTest extends MessageHandlingCompilerTestCase
{
    public function test_it_decorates_booted_containers_with_the_default_ultra_light_container(): void
    {
        $container = $this->ultraLight()
            ->withService(new class () {
                #[Handles]
                public function handle(GreetingMessage $message): string
                {
                    return 'Hello, '.$message->name.'!';
                }
            })
            ->boot()
        ;

        self::assertInstanceOf(UltraLightContainer::class, $container);
        self::assertSame('Hello, Alice!', $container->mediator()->send(new GreetingMessage('Alice')));
    }

    public function test_it_can_decorate_booted_containers_with_a_custom_factory(): void
    {
        /** @var UltraLightContainerFactoryInterface<UltraLightTestContainer> $containerFactory */
        $containerFactory = new class () implements UltraLightContainerFactoryInterface {
            public function create(Container $container): UltraLightTestContainer
            {
                return new UltraLightTestContainer($container);
            }
        };

        $container = $this->ultraLight()
            ->withService(new class () {
                #[Handles]
                public function handle(GreetingMessage $message): string
                {
                    return 'Hello, '.$message->name.'!';
                }
            })
            ->withContainerFactory($containerFactory)
            ->boot()
        ;

        self::assertSame('Hello, Bob!', $container->typedMediator()->send(new GreetingMessage('Bob')));
    }
}
