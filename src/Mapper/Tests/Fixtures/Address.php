<?php

declare(strict_types=1);

namespace Ultra\Mapper\Tests\Fixtures;

readonly class Address
{
    public function __construct(
        public string $street,
        public string $city,
        public string $zip
    ) {}
}
