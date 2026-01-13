<?php

declare(strict_types=1);

namespace Ultra\Mapper\Tests\Fixtures;

readonly class User
{
    public function __construct(
        public string $username,
        public Address $address,
    ) {}
}
