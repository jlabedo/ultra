<?php

declare(strict_types=1);

namespace Ultra\Container;

use Symfony\Component\DependencyInjection\ContainerBuilder;

interface ContainerExtension
{
    public function build(ContainerBuilder $container): void;
}
