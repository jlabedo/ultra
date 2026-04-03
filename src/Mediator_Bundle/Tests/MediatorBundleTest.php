<?php

declare(strict_types=1);

namespace Ultra\Mediator_Bundle\Tests;

use Symfony\Component\DependencyInjection\Container;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Ultra\MessageHandling\Attribute\Handles;
use Ultra\MessageHandling\Attribute\SelfHandler;
use Ultra\MessageHandling\MediatorInterface;
use Ultra\MessageHandling\MessageHandlingBundle;
use Ultra\MessageHandling_Compiler\MessageRouterCompilerPass;

const MEDIATOR_BUNDLE_TEST_OUTPUT_DIR = __DIR__.'/../../../var/mediator_bundle_tests';

final class MediatorBundleTestGreeter
{
    public function greet(string $name): string
    {
        return 'Hello, '.$name.'!';
    }
}

final readonly class BundledSelfGreetingMessage
{
    public function __construct(public string $name) {}

    #[SelfHandler]
    public function greet(MediatorBundleTestGreeter $greeter): string
    {
        return $greeter->greet($this->name);
    }
}

final readonly class BundledRegularGreetingMessage
{
    public function __construct(public string $name) {}
}

test('mediator bundle generates unique class names for different scenarios', function () {
    $first = new ContainerBuilder();
    $first->setParameter('kernel.build_dir', MEDIATOR_BUNDLE_TEST_OUTPUT_DIR.'/a');
    new MessageHandlingBundle()->build($first);
    $first->setAlias('test.mediator', MediatorInterface::class)->setPublic(true);
    $first->register(BundledSelfGreetingMessage::class, BundledSelfGreetingMessage::class)
        ->setAutoconfigured(true)
    ;
    $first->register(MediatorBundleTestGreeter::class, MediatorBundleTestGreeter::class);
    $first->addCompilerPass(new MessageRouterCompilerPass(MEDIATOR_BUNDLE_TEST_OUTPUT_DIR.'/a'));
    $first->compile();

    $second = new ContainerBuilder();
    $second->setParameter('kernel.build_dir', MEDIATOR_BUNDLE_TEST_OUTPUT_DIR.'/b');
    new MessageHandlingBundle()->build($second);
    $second->setAlias('test.mediator', MediatorInterface::class)->setPublic(true);
    $second->register(BundledSelfGreetingMessage::class, BundledSelfGreetingMessage::class)
        ->setAutoconfigured(true)
    ;
    $second->register(MediatorBundleTestGreeter::class, MediatorBundleTestGreeter::class);
    $second->addCompilerPass(new MessageRouterCompilerPass(MEDIATOR_BUNDLE_TEST_OUTPUT_DIR.'/b'));
    $second->compile();

    /** @var MediatorInterface $firstMediator */
    $firstMediator = $first->get('test.mediator');
    /** @var MediatorInterface $secondMediator */
    $secondMediator = $second->get('test.mediator');

    expect($firstMediator->send(new BundledSelfGreetingMessage('Alice')))->toBe('Hello, Alice!')
        ->and($secondMediator->send(new BundledSelfGreetingMessage('Bob')))->toBe('Hello, Bob!')
    ;
});

readonly class UltraLight
{
    /**
     * @param array<int|string, object> $services
     * @param list<class-string>        $classesToDiscover
     * @param array<string, string>     $publicAliases
     */
    public function __construct(
        private array $services = [],
        private array $classesToDiscover = [],
        private array $publicAliases = [],
    ) {}

    public function withClass(string ...$classesToDiscover): self
    {
        return clone($this, [
            'classesToDiscover' => [...$this->classesToDiscover, ...$classesToDiscover],
        ]);
    }

    public function withService(object ...$services): self
    {
        return clone($this, [
            'services' => [...$this->services, ...$services],
        ]);
    }

    public function withAlias(string $alias, string $serviceId): self
    {
        return clone($this, [
            'publicAliases' => [...$this->publicAliases, $alias => $serviceId],
        ]);
    }

    public function boot(): Container
    {
        $container = new ContainerBuilder();
        $container->setParameter('kernel.build_dir', MEDIATOR_BUNDLE_TEST_OUTPUT_DIR.'/a');
        foreach ($this->classesToDiscover as $class) {
            $container->register($class)
                ->setAutoconfigured(true)
                ->setAutowired(false)
            ;
        }
        $primaryServices = $this->registerProvidedServices($container);
        foreach ($this->publicAliases as $alias => $serviceId) {
            $container->setAlias($alias, $serviceId)->setPublic(true);
        }
        new MessageHandlingBundle()->build($container);
        $container->compile();

        foreach ($primaryServices as $id => $service) {
            $container->set($id, $service);
        }

        return $container;
    }

    /**
     * @return array<string, object>
     */
    private function registerProvidedServices(ContainerBuilder $container): array
    {
        $primaryServices = [];

        foreach ($this->services as $serviceId => $service) {
            $primaryId = is_string($serviceId) ? $serviceId : \str_replace("\0", '', $service::class);
            if (isset($primaryServices[$primaryId])) {
                throw new \InvalidArgumentException(
                    \sprintf(': %s is already registered as %s', $service::class, $primaryId)
                );
            }

            $container->register($primaryId, $service::class)
                ->setAutoconfigured(true)
                ->setSynthetic(true)
            ;

            $primaryServices[$primaryId] = $service;
        }

        return $primaryServices;
    }
}


test('it can resolve anonymous classes', function () {
    $handler = new class () {
        #[Handles]
        public function handle(BundledRegularGreetingMessage $message): string
        {
            return 'Hello, '.$message->name.'!';
        }
    };

    $ultra = new UltraLight()->withService($handler)->withAlias('test.mediator', MediatorInterface::class)->boot();
    $mediator = $ultra->get('test.mediator');
    expect($mediator->send(new BundledRegularGreetingMessage('Alice')))->toBe('Hello, Alice!');
});
