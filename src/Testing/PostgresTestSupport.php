<?php

declare(strict_types=1);

namespace Ultra\Testing;

use Doctrine\DBAL\Connection;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Ultra\EventStore\EventStore;
use Ultra\EventStore_Postgres\LockStrategy\BucketLockStrategy;
use Ultra\EventStore_Postgres\LockStrategy\LockStrategy;
use Ultra\EventStore_Postgres\LockStrategy\SharedExclusiveLockStrategy;
use Ultra\EventStore_Postgres\PostgresEventStoreHStore;
use Ultra\EventStore_Postgres\PostgresEventStoreHStoreSchema;
use Ultra\EventStore_Postgres\PostgresEventStoreQueryStreams;
use Ultra\EventStore_Postgres\PostgresEventStoreQueryStreamsSchema;
use Ultra\EventStore_Postgres\PostgresEventStoreSchemaDefinition;

final class PostgresTestSupport
{
    public static function store(Connection $connection, string $tablePrefix): PostgresEventStoreHStore
    {
        return new PostgresEventStoreHStore(
            connection: $connection,
            tablePrefix: $tablePrefix,
        );
    }

    public static function schema(string $tablePrefix): PostgresEventStoreHStoreSchema
    {
        return new PostgresEventStoreHStoreSchema($tablePrefix);
    }

    /**
     * @return list<string>
     */
    public static function supportedImplementations(): array
    {
        return ['hstore', 'query-streams'];
    }

    /**
     * @return list<string>
     */
    public static function supportedLockStrategies(): array
    {
        return ['shared-exclusive', 'bucket'];
    }

    public static function lockStrategy(string $lockStrategy): LockStrategy
    {
        return match (strtolower(trim($lockStrategy))) {
            'shared-exclusive' => new SharedExclusiveLockStrategy(),
            'bucket' => new BucketLockStrategy(),
            default => throw new \InvalidArgumentException(sprintf(
                'Unsupported Postgres event store lock strategy "%s". Expected one of: %s.',
                $lockStrategy,
                implode(', ', self::supportedLockStrategies()),
            )),
        };
    }

    /**
     * @return PostgresEventStoreHStore|PostgresEventStoreQueryStreams
     */
    public static function storeForImplementation(
        Connection $connection,
        string $tablePrefix,
        string $implementation,
        LoggerInterface $logger = new NullLogger(),
        string $lockStrategy = 'shared-exclusive',
    ): EventStore {
        $strategy = self::lockStrategy($lockStrategy);

        return match ($implementation) {
            'hstore' => new PostgresEventStoreHStore(
                connection: $connection,
                tablePrefix: $tablePrefix,
                logger: $logger,
                lockStrategy: $strategy,
            ),
            'query-streams' => new PostgresEventStoreQueryStreams(
                connection: $connection,
                tablePrefix: $tablePrefix,
                logger: $logger,
                lockStrategy: $strategy,
            ),
            default => throw new \InvalidArgumentException(sprintf(
                'Unsupported Postgres event store implementation "%s". Expected one of: hstore, query-streams.',
                $implementation,
            )),
        };
    }

    public static function schemaForImplementation(
        string $tablePrefix,
        string $implementation,
        string $lockStrategy = 'shared-exclusive',
    ): PostgresEventStoreSchemaDefinition {
        $strategy = self::lockStrategy($lockStrategy);

        return match ($implementation) {
            'hstore' => new PostgresEventStoreHStoreSchema($tablePrefix, $strategy),
            'query-streams' => new PostgresEventStoreQueryStreamsSchema($tablePrefix, $strategy),
            default => throw new \InvalidArgumentException(sprintf(
                'Unsupported Postgres event store implementation "%s". Expected one of: hstore, query-streams.',
                $implementation,
            )),
        };
    }

    public static function uniquePrefix(): string
    {
        return 'ultra_event_store_'.bin2hex(random_bytes(4)).'_';
    }
}
