<?php

declare(strict_types=1);

namespace Ultra\Mapper\Tests\Fixtures;

class EnumHolder
{
    public function __construct(
        public Status $status,
        public ?Status $nullableStatus = null,
    ) {}
}
