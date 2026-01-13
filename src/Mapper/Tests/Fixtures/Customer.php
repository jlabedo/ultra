<?php

declare(strict_types=1);

namespace Ultra\Mapper\Tests\Fixtures;

readonly class Customer
{
    public function __construct(
        public string $name,
        public Email $email,
        public string $phone,
    ) {}
}
