<?php

declare(strict_types=1);

namespace Ultra\Mapper_Example\Tests;

use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Ultra\Mapper\UltraNormalizer;
use Ultra\Mapper_Bundle\Cache\MapperCacheWarmer;
use Ultra\Mapper_Example\Domain\RuntimeEmail;
use Ultra\Mapper_Example\Domain\RuntimeEmailNormalizer;
use Ultra\Mapper_Example\Domain\UserRegistered;
use Ultra\Mapper_Example\Kernel;

final class MapperBundleTest extends KernelTestCase
{
    protected static function getKernelClass(): string
    {
        return Kernel::class;
    }

    protected static function createKernel(array $options = []): Kernel
    {
        return new Kernel(
            $options['environment'] ?? 'test',
            $options['debug'] ?? false,
        );
    }

    protected function tearDown(): void
    {
        parent::tearDown();

        restore_exception_handler();
    }

    public function testCacheWarmupUsesRuntimeNormalizers(): void
    {
        self::bootKernel();
        $container = self::getContainer();

        /** @var MapperCacheWarmer $warmer */
        $warmer = $container->get(MapperCacheWarmer::class);
        $warmer->warmUp(self::$kernel->getCacheDir());

        $buildDir = $container->getParameter('kernel.build_dir');
        $cacheFile = $buildDir . '/mapper/__Normalizer_Ultra_Mapper_Example_Domain_UserRegistered.php';
        $this->assertFileExists($cacheFile);

        $normalizer = new UltraNormalizer($buildDir . '/mapper');
        $normalizer->registerRuntimeNormalizer(new RuntimeEmailNormalizer());

        $event = new UserRegistered(new RuntimeEmail('user@example.test'), 'User');
        $payload = $normalizer->toScalar($event);

        $this->assertSame('user@example.test', $payload['email']);
        $this->assertSame('User', $payload['name']);

        $rehydrated = $normalizer->fromScalar($payload, UserRegistered::class);
        $this->assertInstanceOf(UserRegistered::class, $rehydrated);
        $this->assertSame('user@example.test', $rehydrated->email->value());
        $this->assertSame('User', $rehydrated->name);
    }
}
