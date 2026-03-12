<?php

declare(strict_types=1);

namespace Ultra\Mediator_Example\OrderProcessing;

use Symfony\Bundle\FrameworkBundle\FrameworkBundle;
use Symfony\Bundle\FrameworkBundle\Kernel\MicroKernelTrait;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use Symfony\Component\HttpKernel\Kernel as BaseKernel;
use Ultra\Mediator_Compiler\MediatorCompilerPass;
use Ultra\Mediator_Example\OrderProcessing\Aspect\AuditAspect;
use Ultra\Mediator_Example\OrderProcessing\Aspect\CleanupAspect;
use Ultra\Mediator_Example\OrderProcessing\Aspect\MetricsAspect;
use Ultra\Mediator_Example\OrderProcessing\Aspect\TransactionAspect;
use Ultra\Mediator_Example\OrderProcessing\Handler\PlaceOrderHandler;

final class Kernel extends BaseKernel
{
    use MicroKernelTrait;

    public function registerBundles(): iterable
    {
        yield new FrameworkBundle();
    }

    public function build(ContainerBuilder $container): void
    {
        parent::build($container);

        $outputDir = $this->getProjectDir() . '/var/mediator_example';
        $container->addCompilerPass(new MediatorCompilerPass($outputDir, 'CompiledOrderMediator'));
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
                __DIR__ . '/Handler/',
                __DIR__ . '/Aspect/',
            ]);

        $services->set(PlaceOrderHandler::class)
            ->tag('mediator.handler');

        $services->set(TransactionAspect::class)
            ->tag('mediator.aspect');

        $services->set(AuditAspect::class)
            ->tag('mediator.aspect');

        $services->set(MetricsAspect::class)
            ->tag('mediator.aspect');

        $services->set(CleanupAspect::class)
            ->tag('mediator.aspect');
    }
}
