<?php

declare(strict_types=1);

namespace Ultra\Mediator_Compiler\Tests;

use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Definition;
use Ultra\Mediator\Attribute\Handles;
use Ultra\Mediator\MediatorInterface;
use Ultra\Mediator\Attribute\SelfHandler;
use Ultra\Mediator\Tests\Support\MediatorFactoryInterface;
use Ultra\Mediator_Compiler\MediatorCompilerPass;
use Ultra\MessageHandling\Attribute\Advice;
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
        $primaryServices = $this->registerProvidedServices($container, $services);
        $this->registerDiscoverableClasses($container, $classes);
        $container->setAlias('test.mediator', MediatorInterface::class)->setPublic(true);
        $container->addCompilerPass(new MediatorCompilerPass($this->testOutputDir, $this->compiledContractClassName($scenario)));
        $container->compile();

        foreach ($primaryServices as $id => $service) {
            $container->set($id, $service);
        }

        /** @var MediatorInterface $mediator */
        return $container->get('test.mediator');
    }

    private function compiledContractClassName(string $scenario): string
    {
        $sanitized = preg_replace('/[^A-Za-z0-9]+/', '', $scenario);

        return 'CompiledMediatorContract' . ('' === $sanitized ? 'Scenario' : $sanitized);
    }

    /**
     * @return list<string>
     */
    private function mediatorDiscoveryTags(string $class): array
    {
        $tags = [];
        $reflection = new \ReflectionClass($class);

        foreach ($reflection->getMethods(\ReflectionMethod::IS_PUBLIC) as $method) {
            if ([] !== $method->getAttributes(Handles::class)) {
                $tags['mediator.handler'] = true;
            }

            if ([] !== $method->getAttributes(SelfHandler::class)) {
                $tags['mediator.self-handler'] = true;
            }

            if ([] !== $method->getAttributes(Advice::class, \ReflectionAttribute::IS_INSTANCEOF)) {
                $tags['mediator.aspect'] = true;
            }
        }

        return array_keys($tags);
    }

    /**
     * @return list<string>
     */
    private function mediatorServiceAliases(object $service): array
    {
        $reflection = new \ReflectionClass($service);
        $parents = [];
        $parent = $reflection->getParentClass();
        while (false !== $parent) {
            $parents[] = $parent->getName();
            $parent = $parent->getParentClass();
        }

        return [
            ...array_keys($reflection->getInterfaces()),
            ...$parents,
        ];
    }

    /**
     * @param list<object> $services
     *
     * @return array<string, object>
     */
    private function registerProvidedServices(ContainerBuilder $container, array $services): array
    {
        $primaryServices = [];
        $registeredIds = [];

        foreach ($services as $service) {
            $primaryId = $service::class;
            if (isset($primaryServices[$primaryId])) {
                throw new \InvalidArgumentException(\sprintf('Mediator contract services must be unique by class; "%s" was provided more than once.', $primaryId));
            }

            $container->setDefinition(
                $primaryId,
                (new Definition($service::class))
                    ->setSynthetic(true)
            );

            foreach ($this->mediatorDiscoveryTags($primaryId) as $tag) {
                $container->getDefinition($primaryId)->addTag($tag);
            }

            $registeredIds[$primaryId] = $primaryId;

            foreach ($this->mediatorServiceAliases($service) as $alias) {
                if ($alias === $primaryId) {
                    continue;
                }

                if (isset($registeredIds[$alias]) && $registeredIds[$alias] !== $primaryId) {
                    throw new \InvalidArgumentException(\sprintf(
                        'Mediator contract services "%s" and "%s" both expose "%s"; the shared contract requires unique service types.',
                        $registeredIds[$alias],
                        $primaryId,
                        $alias,
                    ));
                }

                $registeredIds[$alias] = $primaryId;
                $container->setAlias($alias, $primaryId);
            }

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
            if ($container->hasDefinition($class) || $container->hasAlias($class)) {
                throw new \InvalidArgumentException(\sprintf(
                    'Discoverable class "%s" was also registered as a service. Only non-service classes belong in the contract class list.',
                    $class,
                ));
            }

            $definition = (new Definition($class))
                ->setAutowired(false);

            foreach ($this->mediatorDiscoveryTags($class) as $tag) {
                $definition->addTag($tag);
            }

            $container->setDefinition($class, $definition);
        }
    }
}

$testOutputDir = dirname(__DIR__, 3) . '/var/mediator_compiler_tests';

$mediatorCompilerFactory = new MediatorCompilerContractFactory($testOutputDir);

registerMediatorBehaviorContractTests($mediatorCompilerFactory);
registerMediatorOrderingContractTests($mediatorCompilerFactory);
