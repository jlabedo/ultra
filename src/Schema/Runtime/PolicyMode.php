<?php

declare(strict_types=1);

namespace Ultra\Schema;

enum PolicyMode: string
{
    case Permissive = 'PERMISSIVE';
    case Restrictive = 'RESTRICTIVE';
}
