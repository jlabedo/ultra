<?php

declare(strict_types=1);

namespace Ultra\Signal;

use Ultra\Time\DatePoint;

final readonly class SuppressionHandle
{
    public function __construct(
        public int $id,
        public string $queueName,
        public BucketRange $bucketRange,
        public string $owner,
        public DatePoint $suppressUntil,
    ) {
        if ($id <= 0) {
            throw new \InvalidArgumentException('Signal suppression id must be positive.');
        }

        if ('' === $queueName) {
            throw new \InvalidArgumentException('Signal suppression queue name must be non-empty.');
        }

        if ('' === $owner) {
            throw new \InvalidArgumentException('Signal suppression owner must be non-empty.');
        }
    }
}
