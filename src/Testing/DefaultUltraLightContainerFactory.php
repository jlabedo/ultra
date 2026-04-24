<?php

declare(strict_types=1);

namespace Ultra\Testing;

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
