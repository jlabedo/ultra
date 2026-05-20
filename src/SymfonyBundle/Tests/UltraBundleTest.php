<?php

declare(strict_types=1);

namespace Ultra\SymfonyBundle\Tests;

use PHPUnit\Framework\TestCase;
use Ultra\Container\ContainerExtension;
use Ultra\SymfonyBundle\UltraBundle;

final class UltraBundleTest extends TestCase
{
    public function test_default_extensions_are_loaded_from_installed_packages(): void
    {
        $extensions = UltraBundle::defaultExtensions();

        self::assertNotSame([], $extensions);
        self::assertContainsOnlyInstancesOf(ContainerExtension::class, $extensions);
    }
}
