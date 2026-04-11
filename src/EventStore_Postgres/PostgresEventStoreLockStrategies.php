<?php

declare(strict_types=1);

namespace Ultra\EventStore_Postgres;

final class PostgresEventStoreLockStrategies
{
    /**
     * @return list<string>
     */
    public static function names(): array
    {
        return ['shared-exclusive', 'bucket'];
    }

    public static function fromName(string $name): PostgresEventStoreLockStrategy
    {
        return match (strtolower(trim($name))) {
            'shared-exclusive' => new PostgresEventStoreSharedExclusiveLockStrategy(),
            'bucket' => new PostgresEventStoreBucketLockStrategy(),
            default => throw new \InvalidArgumentException(sprintf(
                'Unsupported Postgres event store lock strategy "%s". Expected one of: %s.',
                $name,
                implode(', ', self::names()),
            )),
        };
    }
}
