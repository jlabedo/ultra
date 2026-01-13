<?php

declare(strict_types=1);

namespace Ultra\Mapper\Tests\Fixtures;

readonly class Complex
{
    public function __construct(
        public string $name,
        public int $age,
        public float $score,
        public bool $active,
        public Address $address,
        public array $tags,
        public \DateTimeImmutable $createdAt,
    ) {}
}
