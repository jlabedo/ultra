<?php

declare(strict_types=1);

namespace Ultra\Mapper_Example;

use Symfony\Bundle\FrameworkBundle\FrameworkBundle;
use Symfony\Bundle\FrameworkBundle\Kernel\MicroKernelTrait;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use Symfony\Component\HttpKernel\Kernel as BaseKernel;
use Ultra\Mapper_Bundle\MapperBundle;
use Ultra\Mapper_Bundle\TagNames;
use Ultra\Mapper_Example\Domain\UserRegistered;

final class Kernel extends BaseKernel
{
    use MicroKernelTrait;

    public function registerBundles(): iterable
    {
        yield new FrameworkBundle();
        yield new MapperBundle();
    }

    protected function configureContainer(ContainerConfigurator $container): void
    {
        $services = $container->services();

        $container->extension('framework', [
            'test' => true,
            'secret' => 'mapper-example',
        ]);

        $services->defaults()
            ->autowire()
            ->autoconfigure();

        $services->load(__NAMESPACE__ . '\\', __DIR__ . '/')
            ->exclude([
                __DIR__ . '/Kernel.php',
                __DIR__ . '/Domain/RuntimeEmail.php',
                __DIR__ . '/Domain/UserRegistered.php',
            ]);

        $services->set(UserRegistered::class)
            ->autowire(false)
            ->autoconfigure(false)
            ->tag(TagNames::NORMALIZABLE);
    }
}
