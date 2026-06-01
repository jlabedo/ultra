<?php

declare(strict_types=1);

namespace Ultra\Mapper_Example;

use Symfony\Component\DependencyInjection\Kernel\AbstractKernel;
use Symfony\Component\DependencyInjection\Kernel\KernelTrait;
use Symfony\Component\DependencyInjection\Kernel\ServicesBundle;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use Ultra\Mapper_Bundle\MapperExtension;
use Ultra\SymfonyBundle\UltraBundle;
use Ultra\Mapper_Bundle\TagNames;
use Ultra\Mapper_Example\Domain\UserRegistered;

final class Kernel extends AbstractKernel
{
    use KernelTrait;

    public function registerBundles(): iterable
    {
        yield new ServicesBundle();

        yield new UltraBundle([
            new MapperExtension(),
        ]);
    }

    protected function configureContainer(ContainerConfigurator $container): void
    {
        $services = $container->services();

        $services->defaults()
            ->autowire()
            ->autoconfigure()
        ;

        $services->load(__NAMESPACE__.'\\', __DIR__.'/')
            ->exclude([
                __DIR__.'/Kernel.php',
                __DIR__.'/Domain/RuntimeEmail.php',
                __DIR__.'/Domain/UserRegistered.php',
            ])
        ;

        $services->set(UserRegistered::class)
            ->autowire(false)
            ->autoconfigure(false)
            ->tag(TagNames::NORMALIZABLE)
            ->tag('container.excluded')
        ;
    }
}
