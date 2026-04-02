<?php

declare(strict_types=1);

namespace Ultra\Mediator_Example\OrderProcessing;

use Symfony\Bundle\FrameworkBundle\FrameworkBundle;
use Symfony\Bundle\FrameworkBundle\Kernel\MicroKernelTrait;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use Symfony\Component\HttpKernel\Kernel as BaseKernel;
use Ultra\EventBus_Bundle\EventBusBundle;
use Ultra\Events\EventsBundle;
use Ultra\Mediator_Bundle\MediatorBundle;
use Ultra\Mediator_Example\OrderProcessing\Message\SelfHandledPing;
use Ultra\MessageHandling\MediatorInterface;
use Ultra\MessageHandling\MessageHandlingBundle;
use Ultra\MessageHandling\TagNames;

final class Kernel extends BaseKernel
{
    use MicroKernelTrait;

    public function registerBundles(): iterable
    {
        yield new FrameworkBundle();
        yield new EventsBundle();
        yield new MessageHandlingBundle();
    }

    protected function configureContainer(ContainerConfigurator $container): void
    {
        $container->extension('framework', [
            'test' => true,
            'secret' => 'mediator-example',
        ]);

        $services = $container->services();
        $services->defaults()
            ->autowire()
            ->autoconfigure()
        ;
        $services->load(__NAMESPACE__.'\\', __DIR__.'/');

        $services->load(__NAMESPACE__.'\Infrastructure\\', __DIR__.'/Infrastructure/')
            ->public()
        ;

        $services->alias('test.mediator', MediatorInterface::class)->public();
        $services->set(SelfHandledPing::class)
            ->autoconfigure(false)
            ->tag(TagNames::HANDLER)
        ;
    }
}
