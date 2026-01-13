<?php

declare(strict_types=1);

namespace Ultra\Mapper\Tests\Fixtures;

enum Status: string
{
    case ACTIVE = 'active';
    case INACTIVE = 'inactive';
}
