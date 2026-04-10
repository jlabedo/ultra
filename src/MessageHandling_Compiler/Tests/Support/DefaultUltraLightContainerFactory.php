<?php

declare(strict_types=1);

namespace Ultra\MessageHandling_Compiler\Tests\Support;

use Symfony\Component\DependencyInjection\Container;

/**
 * @implements UltraLightContainerFactoryInterface<UltraLightContainer>
 */
final class DefaultUltraLightContainerFactory implements UltraLightContainerFactoryInterface
{
    public function create(Container $container): UltraLightContainer
    {
        return new UltraLightContainer($container);
    }
}
