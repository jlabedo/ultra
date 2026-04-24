<?php

declare(strict_types=1);

namespace Ultra\Testing;

use Symfony\Component\DependencyInjection\Container;

/**
 * @template TContainer of UltraLightContainer
 */
interface UltraLightContainerFactoryInterface
{
    /**
     * @return TContainer
     */
    public function create(Container $container): UltraLightContainer;
}
