<?php

declare(strict_types=1);

namespace Ultra\EventStore;

final readonly class BucketHasher
{
    private function __construct() {}

    public static function bucketFor(string $value): int
    {
        return crc32($value) & 0xff;
    }
}
