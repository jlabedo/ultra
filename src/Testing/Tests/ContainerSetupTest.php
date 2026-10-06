<?php

declare(strict_types=1);

namespace Ultra\Testing\Tests;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\Container;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Ultra\Container\ContainerExtension;
use Ultra\Testing\UltraLight;
use Ultra\Testing\UltraLightContainer;
use Ultra\Testing\UltraLightContainerFactoryInterface;

/** @internal */
#[CoversClass(UltraLight::class)]
#[CoversClass(UltraLightContainer::class)]
final class ContainerSetupTest extends TestCase
{
    public function test_derived_setup_preserves_registration_and_uses_the_selected_factory(): void
    {
        $service = new \stdClass();
        $base = new UltraLight(buildDir: sys_get_temp_dir().'/ultra_testing_'.bin2hex(random_bytes(4)));
        $factory = new class implements UltraLightContainerFactoryInterface {
            public ?Container $received = null;
            public function create(Container $container): UltraLightContainer
            {
                $this->received = $container;
                return new UltraLightContainer($container);
            }
        };
        $configured = $base->withClass(DiscoveredFixture::class)
            ->withEventClass(EventFixture::class)
            ->withService($service, 'provided')
            ->withAlias('selected', 'provided')
            ->withAlias('discovered', DiscoveredFixture::class)
            ->withExtension(new class implements ContainerExtension {
                public function build(ContainerBuilder $container): void
                {
                    $definition = $container->getDefinition(EventFixture::class);
                    $container->setParameter('registered_event', !$definition->isAutowired() && $definition->hasTag('ultra_event_bus.event'));
                }
            })
            ->withBundle(new class implements ContainerExtension {
                public function build(ContainerBuilder $container): void { $container->setParameter('bundle', true); }
            })
            ->withContainerFactory($factory)
            ->withBuildDir('/tmp/ultra-testing-selected');
        $container = $configured->boot();

        self::assertSame($factory->received, $container->container());
        self::assertSame($service, $container->get('selected'));
        self::assertInstanceOf(DiscoveredFixture::class, $container->get('discovered'));
        self::assertTrue($container->getParameter('registered_event'));
        self::assertTrue($container->getParameter('bundle'));
        self::assertSame('/tmp/ultra-testing-selected', $container->getParameter('kernel.build_dir'));
        self::assertFalse($base->boot()->has('selected'));
    }

    public function test_duplicate_service_identity_is_rejected_before_boot(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('already registered');
        new UltraLight()->withService(new \stdClass())->withService(new \stdClass())->boot();
    }
}

/** @internal */
final class DiscoveredFixture {}

/** @internal */
final class EventFixture {}
