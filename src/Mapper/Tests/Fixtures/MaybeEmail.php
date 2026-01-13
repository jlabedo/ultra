<?php

declare(strict_types=1);

namespace Ultra\Mapper\Tests\Fixtures;

readonly class MaybeEmail
{
    public function __construct(
        public ?Email $email
    ) {}
}
