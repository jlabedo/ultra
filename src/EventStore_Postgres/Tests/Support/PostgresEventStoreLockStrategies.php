<?php

declare(strict_types=1);

namespace Ultra\EventStore_Postgres\Tests\Support;

use Ultra\EventStore_Postgres\LockStrategy\BucketLockStrategy;
use Ultra\EventStore_Postgres\LockStrategy\LockStrategy;
use Ultra\EventStore_Postgres\LockStrategy\SharedExclusiveLockStrategy;

final class PostgresEventStoreLockStrategies
{
    /**
     * @return list<string>
     */
    public static function names(): array
    {
        return ['shared-exclusive', 'bucket'];
    }

    public static function fromName(string $name): LockStrategy
    {
        return match (strtolower(trim($name))) {
            'shared-exclusive' => new SharedExclusiveLockStrategy(),
            'bucket' => new BucketLockStrategy(),
            default => throw new \InvalidArgumentException(sprintf(
                'Unsupported Postgres event store lock strategy "%s". Expected one of: %s.',
                $name,
                implode(', ', self::names()),
            )),
        };
    }
}
