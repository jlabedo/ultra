<?php

declare(strict_types=1);

namespace Ultra\Mediator_Example\OrderProcessing;

use Symfony\Bundle\FrameworkBundle\FrameworkBundle;
use Symfony\Bundle\FrameworkBundle\Kernel\MicroKernelTrait;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use Symfony\Component\HttpKernel\Kernel as BaseKernel;
use Ultra\Mediator_Bundle\MediatorBundle;

final class Kernel extends BaseKernel
{
    use MicroKernelTrait;

    public function registerBundles(): iterable
    {
        yield new FrameworkBundle();
        yield new MediatorBundle();
    }

    protected function configureContainer(ContainerConfigurator $container): void
    {
        $services = $container->services();

        $container->extension('framework', [
            'test' => true,
            'secret' => 'mediator-example',
        ]);

        $services->defaults()
            ->autowire()
            ->autoconfigure()
            ->public();

        $services->load(__NAMESPACE__ . '\\', __DIR__ . '/')
            ->exclude([
                __DIR__ . '/Kernel.php',
                __DIR__ . '/Domain/',
            ]);
    }
}
