<?php

declare(strict_types=1);

namespace App\Tests;

use App\Kernel;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Tools\DsnParser;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Ultra\EventStore_Postgres\PostgresEventStoreHStoreSchema;

abstract class FacultyKernelTestCase extends KernelTestCase
{
    private ?string $tablePrefix = null;

    protected function setUp(): void
    {
        parent::setUp();

        self::ensureKernelShutdown();
        $this->tablePrefix = $this->buildTablePrefix();
        $this->setTablePrefixEnv($this->tablePrefix);
        $this->createEventStoreSchemaIfNeeded();
    }

    protected function tearDown(): void
    {
        self::ensureKernelShutdown();
        $this->dropEventStoreSchemaIfNeeded();
        $this->clearTablePrefixEnv();

        parent::tearDown();

        restore_exception_handler();
    }

    protected static function getKernelClass(): string
    {
        return Kernel::class;
    }

    private function buildTablePrefix(): string
    {
        $token = (string) ($_SERVER['TEST_TOKEN'] ?? $_ENV['TEST_TOKEN'] ?? 'default');
        $token = preg_replace('/[^a-z0-9_]+/i', '_', $token) ?: 'default';
        $token = substr(strtolower($token), 0, 12);
        $hash = substr(hash('sha1', static::class . '::' . $this->name()), 0, 12);

        return sprintf('faculty_%s_%s_', $token, $hash);
    }

    private function setTablePrefixEnv(string $tablePrefix): void
    {
        putenv('FACULTY_EVENT_STORE_TABLE_PREFIX=' . $tablePrefix);
        $_ENV['FACULTY_EVENT_STORE_TABLE_PREFIX'] = $tablePrefix;
        $_SERVER['FACULTY_EVENT_STORE_TABLE_PREFIX'] = $tablePrefix;
    }

    private function clearTablePrefixEnv(): void
    {
        putenv('FACULTY_EVENT_STORE_TABLE_PREFIX');
        unset($_ENV['FACULTY_EVENT_STORE_TABLE_PREFIX'], $_SERVER['FACULTY_EVENT_STORE_TABLE_PREFIX']);
        $this->tablePrefix = null;
    }

    private function createEventStoreSchemaIfNeeded(): void
    {
        $connection = $this->createPostgresConnection();
        if (null === $connection || null === $this->tablePrefix) {
            return;
        }

        foreach (new PostgresEventStoreHStoreSchema($this->tablePrefix)->dropStatements() as $statement) {
            $connection->executeStatement($statement);
        }

        foreach (new PostgresEventStoreHStoreSchema($this->tablePrefix)->createStatements() as $statement) {
            $connection->executeStatement($statement);
        }

        $connection->close();
    }

    private function dropEventStoreSchemaIfNeeded(): void
    {
        $connection = $this->createPostgresConnection();
        if (null === $connection || null === $this->tablePrefix) {
            return;
        }

        foreach (new PostgresEventStoreHStoreSchema($this->tablePrefix)->dropStatements() as $statement) {
            $connection->executeStatement($statement);
        }

        $connection->close();
    }

    private function createPostgresConnection(): ?Connection
    {
        $databaseUrl = strtolower(trim((string) ($_SERVER['DATABASE_URL'] ?? $_ENV['DATABASE_URL'] ?? '')));

        if (!str_starts_with($databaseUrl, 'pgsql://') && !str_starts_with($databaseUrl, 'postgresql://')) {
            return null;
        }

        return DriverManager::getConnection(new DsnParser([
            'pgsql' => 'pdo_pgsql',
            'postgresql' => 'pdo_pgsql',
        ])->parse((string) ($_SERVER['DATABASE_URL'] ?? $_ENV['DATABASE_URL'] ?? '')));
    }
}
