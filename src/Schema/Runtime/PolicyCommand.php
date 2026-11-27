<?php

declare(strict_types=1);

namespace Ultra\Schema;

enum PolicyCommand: string
{
    case All = 'ALL';
    case Select = 'SELECT';
    case Insert = 'INSERT';
    case Update = 'UPDATE';
    case Delete = 'DELETE';
}
