<?php

declare(strict_types=1);

namespace MappedTableSymfonyExample;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Tools\DsnParser;

/** Owns an isolated schema for the demo and its integration tests. */
final readonly class DemoDatabase
{
    private function __construct(
        private string $dsn,
        private string $schema,
        public Connection $connection,
    ) {}

    public static function open(string $dsn): self
    {
        $schema = 'mapped_table_workshops_' . bin2hex(random_bytes(8));
        $ddl = file_get_contents(dirname(__DIR__) . '/schema.sql');
        if (false === $ddl) {
            throw new \RuntimeException('Cannot read the workshop schema.');
        }

        $connection = self::connect($dsn);
        try {
            $connection->transactional(static function (Connection $connection) use ($schema, $ddl): void {
                $identifier = $connection->quoteSingleIdentifier($schema);
                $connection->executeStatement('CREATE SCHEMA ' . $identifier);
                $connection->executeStatement('SET search_path TO ' . $identifier);
                $connection->executeStatement($ddl);
            });
        } catch (\Throwable $error) {
            $connection->close();

            throw $error;
        }

        return new self($dsn, $schema, $connection);
    }

    public function newSession(): Connection
    {
        $session = self::connect($this->dsn);
        try {
            $session->executeStatement('SET search_path TO ' . $session->quoteSingleIdentifier($this->schema));
        } catch (\Throwable $error) {
            $session->close();

            throw $error;
        }

        return $session;
    }

    public function close(): void
    {
        try {
            $this->connection->executeStatement(
                'DROP SCHEMA ' . $this->connection->quoteSingleIdentifier($this->schema) . ' CASCADE',
            );
        } finally {
            $this->connection->close();
        }
    }

    private static function connect(string $dsn): Connection
    {
        $parser = new DsnParser(['postgres' => 'pdo_pgsql', 'postgresql' => 'pdo_pgsql']);

        return DriverManager::getConnection($parser->parse($dsn));
    }
}
