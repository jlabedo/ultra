<?php

declare(strict_types=1);

namespace Ultra\Container\Tests\Support;

use Symfony\Component\DependencyInjection\ContainerBuilder;
use Ultra\Container\ContainerExtension;

final readonly class RegisteringExtension implements ContainerExtension
{
    public function __construct(private string $name)
    {
    }

    public function build(ContainerBuilder $container): void
    {
        $sequence = $container->hasParameter('extensions') ? $container->getParameter('extensions') : [];
        $container->setParameter('extensions', [...$sequence, $this->name]);
        $container->register('extension.result', \ArrayObject::class)
            ->setArguments([['extension' => $this->name]])
            ->setPublic(true)
        ;
    }
}
