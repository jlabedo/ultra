<?php

declare(strict_types=1);

namespace Ultra\Mediator_Example\OrderProcessing;

use Symfony\Component\DependencyInjection\Kernel\AbstractKernel;
use Symfony\Component\DependencyInjection\Kernel\KernelTrait;
use Symfony\Component\DependencyInjection\Kernel\ServicesBundle;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use Ultra\Events\EventsExtension;
use Ultra\Mediator_Example\OrderProcessing\Message\SelfHandledPing;
use Ultra\MessageHandling\MediatorInterface;
use Ultra\MessageHandling\MessageHandlingExtension;
use Ultra\MessageHandling\TagNames;
use Ultra\SymfonyBundle\UltraBundle;

final class Kernel extends AbstractKernel
{
    use KernelTrait;

    public function registerBundles(): iterable
    {
        yield new ServicesBundle();

        yield new UltraBundle([
            new EventsExtension(),
            new MessageHandlingExtension(),
        ]);
    }

    protected function configureContainer(ContainerConfigurator $container): void
    {
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
            ->resourceTag(TagNames::SELF_HANDLER, ['method' => 'handle'])
        ;
    }
}
