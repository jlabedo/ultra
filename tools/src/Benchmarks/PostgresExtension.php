<?php

declare(strict_types=1);

namespace UltraDev\Tools\Benchmarks;

use PhpBench\DependencyInjection\Container;
use PhpBench\DependencyInjection\ExtensionInterface;
use PhpBench\Environment\Information;
use PhpBench\Environment\ProviderInterface;
use PhpBench\Extension\RunnerExtension;
use Symfony\Component\OptionsResolver\OptionsResolver;

/** Adds database metadata to native PHPBench results without instrumenting measured calls. */
final class PostgresExtension implements ExtensionInterface, ProviderInterface
{
    public function configure(OptionsResolver $resolver): void {}

    public function load(Container $container): void
    {
        $container->register(self::class, static fn(): self => new self(), [
            RunnerExtension::TAG_ENV_PROVIDER => ['name' => 'postgres'],
        ]);
    }

    public function isApplicable(): bool
    {
        return false !== getenv('ULTRA_TEST_POSTGRES_DSN');
    }

    public function getInformation(): Information
    {
        require_once dirname(__DIR__, 3) . '/vendor/autoload.php';

        $connection = \Ultra\Testing\PostgresConnectionSupport::connection(getenv('ULTRA_TEST_POSTGRES_DSN'));
        try {
            $settings = $connection->fetchAllKeyValue(
                "SELECT name, current_setting(name) FROM pg_settings WHERE name IN
                 ('server_version', 'shared_buffers', 'work_mem', 'fsync', 'synchronous_commit',
                  'jit', 'autovacuum', 'gin_pending_list_limit') ORDER BY name",
            );
            return new Information('postgres', [
                'driver' => $connection->getParams()['driver'],
                'dbal' => \Composer\InstalledVersions::getPrettyVersion('doctrine/dbal'),
                ...$settings,
            ]);
        } finally {
            $connection->close();
        }
    }
}
