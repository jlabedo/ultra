<?php

declare(strict_types=1);

namespace Ultra\MappedTable;

interface MappedRowSetDefinition
{
    public function mapping(): MappedRowSetMapping;
}
