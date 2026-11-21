<?php

declare(strict_types=1);

namespace App\TermsOfUse\Persistence;

use Ultra\MappedTable\MappedColumn;
use Ultra\MappedTable\MappedRowSetDefinition;
use Ultra\MappedTable\MappedRowSetMapping;

final class CurrentTermsDefinition implements MappedRowSetDefinition
{
    public function mapping(): MappedRowSetMapping
    {
        return new MappedRowSetMapping(
            'current_terms_of_use',
            [
                'state_id' => new MappedColumn('string'),
                'terms_id' => new MappedColumn('string'),
                'terms_url' => new MappedColumn('string'),
            ],
            ['state_id'],
        );
    }
}
