<?php

declare(strict_types=1);

namespace Ultra\Testing\Tests;

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Process\Process;

/** @internal Verifies dependency installation in a separate application with Composer. */
#[Group('slow')]
final class ComposerConsumerTest extends TestCase
{
    private string $directory;

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir() . '/ultra_composer_consumer_' . bin2hex(random_bytes(8));
        mkdir($this->directory, 0o777, true);
    }

    public function test_the_complete_package_runs_without_development_dependencies(): void
    {
        $manifest = [
            'name' => 'ultra-verification/consumer',
            'repositories' => [[
                'type' => 'path',
                'url' => dirname(__DIR__, 3),
                'options' => ['symlink' => false, 'versions' => ['ultra/ultra' => 'dev-main']],
            ]],
            'require' => [
                'ultra/ultra' => 'dev-main',
                'ultra/coordination' => 'dev-main',
                'ultra/coordination-postgres' => 'dev-main',
            ],
            'config' => ['allow-plugins' => false],
        ];
        file_put_contents(
            $this->directory . '/composer.json',
            json_encode($manifest, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT),
        );
        copy(__DIR__ . '/Fixtures/ComposerConsumer/smoke.php', $this->directory . '/smoke.php');

        $installation = new Process(
            ['composer', 'install', '--no-dev', '--no-interaction', '--no-progress', '--prefer-dist'],
            $this->directory,
            ['COMPOSER_HOME' => $this->directory . '/composer-home', 'COMPOSER_AUTH' => '{}'],
            timeout: 180,
        );
        static::assertSame(0, $installation->run(), $installation->getOutput() . $installation->getErrorOutput());

        $application = new Process([PHP_BINARY, $this->directory . '/smoke.php'], $this->directory);
        static::assertSame(0, $application->run(), $application->getOutput() . $application->getErrorOutput());
        static::assertStringContainsString('Consumer smoke passed.', $application->getOutput());
    }

    protected function tearDown(): void
    {
        new Filesystem()->remove($this->directory);
        parent::tearDown();
    }
}
