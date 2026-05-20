<?php

declare(strict_types=1);

namespace Ultra\Mediator_Example\OrderProcessing;

use Symfony\Bundle\FrameworkBundle\FrameworkBundle;
use Symfony\Bundle\FrameworkBundle\Kernel\MicroKernelTrait;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use Symfony\Component\HttpKernel\Kernel as BaseKernel;
use Ultra\Events\EventsExtension;
use Ultra\Mediator_Example\OrderProcessing\Message\SelfHandledPing;
use Ultra\MessageHandling\MediatorInterface;
use Ultra\MessageHandling\MessageHandlingExtension;
use Ultra\MessageHandling\TagNames;
use Ultra\SymfonyBundle\UltraBundle;

final class Kernel extends BaseKernel
{
    use MicroKernelTrait;

    public function registerBundles(): iterable
    {
        yield new FrameworkBundle();

        yield new UltraBundle([
            new EventsExtension(),
            new MessageHandlingExtension(),
        ]);
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
            ->resourceTag(TagNames::SELF_HANDLER, ['method' => 'handle'])
        ;
    }
}
