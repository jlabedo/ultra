<?php

declare(strict_types=1);

namespace Ultra\Mapper_Example\Domain;

final class UserRegistered
{
    public function __construct(
        public RuntimeEmail $email,
        public string $name,
    ) {
    }
}
