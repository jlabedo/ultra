<?php

declare(strict_types=1);

namespace Ultra\Mapper\Tests\Fixtures;

readonly class DeepNest
{
    public function __construct(
        public User $user,
        public EmailList $emails,
    ) {}
}
