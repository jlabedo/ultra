<?php

declare(strict_types=1);

namespace Ultra\EventStore;

final readonly class BucketHasher
{
    private function __construct() {}

    public static function bucketFor(string $value): int
    {
        return (int) hexdec(substr(md5($value), 0, 2));
    }
}
