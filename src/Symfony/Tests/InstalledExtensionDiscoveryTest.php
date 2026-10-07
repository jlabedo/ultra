<?php

declare(strict_types=1);

namespace Ultra\Container\Tests;

use Composer\Autoload\ClassLoader;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Ultra\Container\ContainerExtension;
use Ultra\Events\EventsExtension;
use Ultra\EventSourcedState\EventSourcedStateExtension;
use Ultra\Mapper_Bundle\MapperExtension;
use Ultra\MessageHandling\MessageHandlingExtension;
use Ultra\Projection\Bundle\ProjectionExtension;
use Ultra\StatefulMessageHandling\StatefulMessageHandlingExtension;
use Ultra\StateStore_Bundle\StateStoreExtension;
use Ultra\SymfonyBundle\UltraBundle;
use Ultra\UnitOfWork\UnitOfWorkExtension;
use Ultra\UnitOfWork\UnitOfWorkRunner;

/** @internal */
#[CoversClass(UltraBundle::class)]
final class InstalledExtensionDiscoveryTest extends TestCase
{
    /** @var list<class-string<ContainerExtension>> */
    private const array EXTENSIONS = [
        EventsExtension::class,
        MessageHandlingExtension::class,
        UnitOfWorkExtension::class,
        MapperExtension::class,
        EventSourcedStateExtension::class,
        StateStoreExtension::class,
        StatefulMessageHandlingExtension::class,
        ProjectionExtension::class,
    ];

    public function test_available_packages_supply_all_default_extensions_in_order(): void
    {
        $extensions = UltraBundle::defaultExtensions();

        static::assertSame(self::EXTENSIONS, array_map(get_class(...), $extensions));
        static::assertContainsOnlyInstancesOf(ContainerExtension::class, $extensions);
    }

    /**
     * @param list<class-string<ContainerExtension>> $available
     */
    #[DataProvider('optionalPackages')]
    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function test_absent_optional_packages_are_skipped_without_preventing_assembly(array $available): void
    {
        self::hideExtensions(array_diff(self::EXTENSIONS, $available));

        $extensions = UltraBundle::defaultExtensions();
        static::assertSame($available, array_map(get_class(...), $extensions));

        $container = new ContainerBuilder();
        new UltraBundle()->build($container);

        static::assertSame($available !== [], $container->hasAlias(UnitOfWorkRunner::class));
    }

    /** @return iterable<string, array{list<class-string<ContainerExtension>>}> */
    public static function optionalPackages(): iterable
    {
        yield 'one installed package' => [[UnitOfWorkExtension::class]];
        yield 'no optional packages' => [[]];
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function test_an_installed_extension_must_implement_the_container_contract(): void
    {
        self::hideExtensions(self::EXTENSIONS);
        require __DIR__ . '/Support/InvalidInstalledExtension.php';

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage(
            'Ultra extension "Ultra\\Events\\EventsExtension" must implement "Ultra\\Container\\ContainerExtension".',
        );

        UltraBundle::defaultExtensions();
    }

    /**
     * Block package discovery only in an isolated PHP process, before a class is loaded.
     *
     * @param array<class-string> $extensions
     */
    private static function hideExtensions(array $extensions): void
    {
        foreach ($extensions as $extension) {
            if (class_exists($extension, false)) {
                throw new \LogicException('Extension already loaded: ' . $extension);
            }
        }

        foreach (ClassLoader::getRegisteredLoaders() as $loader) {
            $loader->addClassMap(array_fill_keys($extensions, false));
        }
    }
}
