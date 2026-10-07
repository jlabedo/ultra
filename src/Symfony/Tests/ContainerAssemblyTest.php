<?php

declare(strict_types=1);

namespace Ultra\Container\Tests;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Ultra\Container\Tests\Support\RegisteringExtension;
use Ultra\Mapper\UltraNormalizer;
use Ultra\MessageHandling\MediatorInterface;
use Ultra\StateStore\StateStore;
use Ultra\SymfonyBundle\UltraBundle;
use Ultra\UnitOfWork\UnitOfWorkRunner;

/** @internal */
#[CoversClass(UltraBundle::class)]
final class ContainerAssemblyTest extends TestCase
{
    /**
     * @param list<string> $names
     */
    #[DataProvider('explicitExtensionOrders')]
    public function test_explicit_extensions_build_the_supplied_container_in_order(array $names): void
    {
        $container = new ContainerBuilder();
        $bundle = new UltraBundle(array_map(static fn(string $name) => new RegisteringExtension($name), $names));

        $bundle->build($container);

        static::assertSame($names, $container->getParameter('extensions'));
        static::assertFalse($container->has(UltraNormalizer::class));
        $container->compile();
        static::assertSame(
            ['extension' => $names[array_key_last($names)]],
            $container->get('extension.result')->getArrayCopy(),
        );
    }

    /** @return iterable<string, array{list<string>}> */
    public static function explicitExtensionOrders(): iterable
    {
        yield 'declared order' => [['first', 'second']];
        yield 'reversed order' => [['second', 'first']];
        yield 'repeated extension' => [['first', 'second', 'first']];
    }

    #[DataProvider('defaultSelection')]
    public function test_empty_extension_selection_registers_installed_package_services(bool $explicitEmptyList): void
    {
        $container = new ContainerBuilder();
        $container->setParameter('kernel.build_dir', __DIR__ . '/../../../var/container-assembly');
        $bundle = $explicitEmptyList ? new UltraBundle([]) : new UltraBundle();

        $bundle->build($container);

        static::assertTrue($container->hasDefinition(UltraNormalizer::class));
        static::assertTrue($container->hasDefinition(MediatorInterface::class));
        static::assertTrue($container->hasDefinition(StateStore::class));
        static::assertTrue($container->hasAlias(UnitOfWorkRunner::class));
    }

    /** @return iterable<string, array{bool}> */
    public static function defaultSelection(): iterable
    {
        yield 'constructor default' => [false];
        yield 'explicit empty list' => [true];
    }
}
