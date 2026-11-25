<?php

declare(strict_types=1);

namespace Ultra\MappedTable;

/** A JSON null at the root of a JSONB value. PHP null at the root means SQL NULL. */
enum JsonNull
{
    case Value;
}
