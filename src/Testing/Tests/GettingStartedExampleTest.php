<?php

declare(strict_types=1);

namespace Ultra\Testing\Tests;

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Process\Process;
use Ultra\Testing\PostgresConnectionSupport;

/** @internal Exercises the documented application journeys in a fresh Composer consumer. */
#[Group('slow')]
final class GettingStartedExampleTest extends TestCase
{
    public function test_the_documented_journeys_persist_across_application_processes(): void
    {
        $dsn = getenv('ULTRA_TEST_POSTGRES_DSN');
        if (false === $dsn || '' === $dsn) {
            static::markTestSkipped('Set ULTRA_TEST_POSTGRES_DSN to verify the getting-started application.');
        }

        $root = dirname(__DIR__, 3);
        $directory = sys_get_temp_dir() . '/ultra_getting_started_' . bin2hex(random_bytes(8));
        $schema = 'getting_started_' . bin2hex(random_bytes(8));
        $filesystem = new Filesystem();
        $connection = PostgresConnectionSupport::connection($dsn);
        $connection->executeStatement('CREATE SCHEMA ' . $schema);

        try {
            foreach (['src', 'config', 'bin'] as $folder) {
                $filesystem->mirror($root . '/examples/getting-started/' . $folder, $directory . '/' . $folder);
            }
            $manifest = json_decode(
                file_get_contents($root . '/examples/getting-started/composer.json'),
                true,
                flags: JSON_THROW_ON_ERROR,
            );
            $manifest['repositories'][0]['url'] = $root;
            file_put_contents($directory . '/composer.json', json_encode($manifest, JSON_THROW_ON_ERROR));
            $installation = new Process(
                ['composer', 'install', '--no-dev', '--no-interaction', '--no-progress', '--prefer-dist'],
                $directory,
                ['COMPOSER_HOME' => $directory . '/composer-home', 'COMPOSER_AUTH' => '{}'],
                timeout: 180,
            );
            static::assertSame(0, $installation->run(), $installation->getOutput() . $installation->getErrorOutput());

            $environment = ['DATABASE_URL' => $dsn, 'PGOPTIONS' => '-c search_path=' . $schema];
            $run = static function (array $arguments, string $expected) use ($directory, $environment): void {
                $process = new Process([PHP_BINARY, ...$arguments], $directory, $environment, timeout: 60);
                static::assertSame(0, $process->run(), $process->getOutput() . $process->getErrorOutput());
                static::assertStringContainsString($expected, $process->getOutput());
            };
            $connection->executeStatement('SET search_path TO ' . $schema);

            $run(['bin/console', 'cache:warmup'], 'Cache for the "dev" environment');
            $run(['bin/setup.php'], 'Tutorial tables created.');
            $run(['bin/mapper.php'], '{"name":"Ada","visits":3}');
            $run(['bin/messages.php'], "before\nafter\nHello, Ada!");
            $run(['bin/console', 'app:contact:rename', 'ada', 'Ada'], 'ada: Ada');
            $run(['bin/console', 'app:contact:rename', 'ada', 'Ada Lovelace'], 'ada: Ada Lovelace');
            static::assertSame('Ada Lovelace', $connection->fetchOne('SELECT name FROM tutorial_contacts WHERE id = ?', [
                'ada',
            ]));

            $run(['bin/events.php'], 'Stored and loaded 1 event.');
            $run(['bin/async.php', 'send'], 'Contact and queued notification committed.');
            static::assertSame(2, (int) $connection->fetchOne('SELECT COUNT(*) FROM tutorial_contacts'));
            static::assertSame(1, (int) $connection->fetchOne('SELECT COUNT(*) FROM tutorial_queue_messages'));
            static::assertSame(0, (int) $connection->fetchOne('SELECT COUNT(*) FROM tutorial_notifications'));
            $connection->executeStatement(
                "ALTER TABLE tutorial_notifications ADD CONSTRAINT reject_body CHECK (body <> 'Welcome, Ada!')",
            );
            $failure = new Process([PHP_BINARY, 'bin/async.php', 'consume'], $directory, $environment, timeout: 60);
            static::assertNotSame(0, $failure->run());
            static::assertStringContainsString('reject_body', $failure->getOutput() . $failure->getErrorOutput());
            static::assertSame(
                1,
                (int) $connection->fetchOne('SELECT COUNT(*) FROM tutorial_queue_messages WHERE lease_id IS NULL'),
            );
            static::assertSame(0, (int) $connection->fetchOne('SELECT COUNT(*) FROM tutorial_notifications'));
            $connection->executeStatement('ALTER TABLE tutorial_notifications DROP CONSTRAINT reject_body');
            $run(['bin/async.php', 'consume'], 'Processed 1 notification(s).');
            $run(['bin/async.php', 'consume'], 'Processed 0 notification(s).');
            static::assertSame(0, (int) $connection->fetchOne('SELECT COUNT(*) FROM tutorial_queue_messages'));
            static::assertSame('Welcome, Ada!', $connection->fetchOne('SELECT body FROM tutorial_notifications'));

            $run(['bin/projections.php', 'append'], 'Read-model rows: 0');
            $run(['bin/projections.php', 'work'], 'Projected 2 event(s).');
            $rows = $connection->fetchAllAssociative('SELECT * FROM tutorial_course_names ORDER BY id');
            static::assertCount(2, $rows);
            $run(['bin/projections.php', 'work'], 'Projected 0 event(s).');
            $run(['bin/projections.php', 'rebuild'], 'Rebuilt from 2 event(s).');
            static::assertSame(
                $rows,
                $connection->fetchAllAssociative('SELECT * FROM tutorial_course_names ORDER BY id'),
            );
        } finally {
            $connection->executeStatement('SET search_path TO public');
            $connection->executeStatement('DROP SCHEMA ' . $schema . ' CASCADE');
            $connection->close();
            $filesystem->remove($directory);
        }
    }
}
