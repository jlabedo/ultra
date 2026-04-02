<?php

declare(strict_types=1);

namespace Ultra\Mediator_Compiler\Tests;

use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Definition;
use Ultra\Mediator\Tests\Support\MediatorFactoryInterface;
use Ultra\MessageHandling\Attribute\Advice;
use Ultra\MessageHandling\Attribute\Handles;
use Ultra\MessageHandling\Attribute\SelfHandler;
use Ultra\MessageHandling\MediatorInterface;
use Ultra\MessageHandling\MessageHandlingBundle;
use Ultra\MessageHandling_Compiler\MessageRouterCompilerPass;
use function Ultra\Mediator\Tests\Support\registerMediatorBehaviorContractTests;
use function Ultra\Mediator\Tests\Support\registerMediatorOrderingContractTests;

require_once dirname(__DIR__, 2) . '/Mediator/Tests/Support/MediatorBehaviorContract.php';
require_once dirname(__DIR__, 2) . '/Mediator/Tests/Support/MediatorOrderingContract.php';

final readonly class MediatorCompilerContractFactory implements MediatorFactoryInterface
{
    public function __construct(private string $testOutputDir) {}

    public function createMediator(array $services, array $classes, string $scenario): MediatorInterface
    {
        $container = new ContainerBuilder();
        $container->setParameter('kernel.build_dir', $this->testOutputDir . '/' . $this->sanitizeScenarioName($scenario));
        $primaryServices = $this->registerProvidedServices($container, $services);
        $this->registerDiscoverableClasses($container, $classes);
        $container->setAlias('test.mediator', MediatorInterface::class)->setPublic(true);
        new MessageHandlingBundle()->build($container);
        $container->compile();

        foreach ($primaryServices as $id => $service) {
            $container->set($id, $service);
        }

        /** @var MediatorInterface $mediator */
        return $container->get('test.mediator');
    }

    private function sanitizeScenarioName(string $scenario): string
    {
        $sanitized = preg_replace('/[^A-Za-z0-9]+/', '', $scenario);

        return '' === $sanitized ? 'Scenario' : $sanitized;
    }

    /**
     * @param array<mixed, object> $services
     *
     * @return array<string, object>
     */
    private function registerProvidedServices(ContainerBuilder $container, array $services): array
    {
        $primaryServices = [];

        foreach ($services as $serviceId => $service) {
            $primaryId = is_string($serviceId) ? $serviceId : $service::class;
            if (isset($primaryServices[$primaryId])) {
                throw new \InvalidArgumentException(\sprintf('Mediator contract services must be unique by class; "%s" was provided more than once.', $primaryId));
            }

            $container->register($primaryId, $service::class)
                ->setAutoconfigured(true)
                ->setSynthetic(true)
            ;

            $primaryServices[$primaryId] = $service;
        }

        return $primaryServices;
    }

    /**
     * @param list<class-string> $classes
     */
    private function registerDiscoverableClasses(ContainerBuilder $container, array $classes): void
    {
        foreach ($classes as $class) {
            $container->register($class)
                ->setAutoconfigured(true)
                ->setAutowired(false)
            ;
        }
    }
}

$testOutputDir = dirname(__DIR__, 3) . '/var/mediator_compiler_tests';

$mediatorCompilerFactory = new MediatorCompilerContractFactory($testOutputDir);

registerMediatorBehaviorContractTests($mediatorCompilerFactory);
registerMediatorOrderingContractTests($mediatorCompilerFactory);
