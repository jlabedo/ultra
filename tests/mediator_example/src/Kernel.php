<?php

declare(strict_types=1);

namespace Ultra\Mediator_Example\OrderProcessing;

use Symfony\Bundle\FrameworkBundle\FrameworkBundle;
use Symfony\Bundle\FrameworkBundle\Kernel\MicroKernelTrait;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use Symfony\Component\HttpKernel\Kernel as BaseKernel;
use Ultra\EventBus_Bundle\EventBusBundle;
use Ultra\Mediator_Bundle\MediatorBundle;
use Ultra\Mediator_Bundle\TagNames;
use Ultra\Mediator_Example\OrderProcessing\Infrastructure\PublishedEvents;
use Ultra\Mediator_Example\OrderProcessing\Infrastructure\SelfHandlingTracker;
use Ultra\Mediator_Example\OrderProcessing\Message\SelfHandledPing;

final class Kernel extends BaseKernel
{
    use MicroKernelTrait;

    public function registerBundles(): iterable
    {
        yield new FrameworkBundle();
        yield new MediatorBundle();
        yield new EventBusBundle();
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

        $services->set(SelfHandledPing::class)
            ->autoconfigure(false)
            ->tag(TagNames::SELF_HANDLER);

        $services->set(SelfHandlingTracker::class);
        $services->set(PublishedEvents::class);
    }
}
