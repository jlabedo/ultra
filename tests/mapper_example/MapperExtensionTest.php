<?php

declare(strict_types=1);

namespace Ultra\Mapper_Example\Tests;

use PHPUnit\Framework\TestCase;
use Ultra\Mapper\UltraNormalizer;
use Ultra\Mapper_Example\Domain\RuntimeEmail;
use Ultra\Mapper_Example\Domain\RuntimeEmailNormalizer;
use Ultra\Mapper_Example\Domain\UserRegistered;
use Ultra\Mapper_Example\Kernel;

/**
 * @internal
 *
 * @coversNothing
 */
final class MapperExtensionTest extends TestCase
{
    private static ?Kernel $kernel = null;

    protected function tearDown(): void
    {
        parent::tearDown();

        restore_exception_handler();
    }

    public function test_container_compilation_generates_normalizers_with_runtime_normalizers(): void
    {
        self::bootKernel();

        $cacheDir = self::$kernel->getBuildDir().'/mapper';
        $cacheFile = $cacheDir.'/__Normalizer_Ultra_Mapper_Example_Domain_UserRegistered.php';
        $this->assertFileExists($cacheFile);

        $normalizer = new UltraNormalizer($cacheDir);
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

    protected static function bootKernel(array $options = []): void
    {
        if (self::$kernel !== null) {
            self::$kernel->shutdown();
            self::$kernel = null;
        }
        self::$kernel = self::createKernel($options);
        self::$kernel->boot();
    }

    protected static function createKernel(array $options = []): Kernel
    {
        return new Kernel(
            $options['environment'] ?? 'test',
            $options['debug'] ?? true,
        );
    }
}
