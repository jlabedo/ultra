<?php

declare(strict_types=1);

namespace Ultra\Schema;

enum ForeignKeyAction: string
{
    case NoAction = 'NO ACTION';
    case Cascade = 'CASCADE';
}
