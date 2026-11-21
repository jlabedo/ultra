<?php

declare(strict_types=1);

namespace App\TermsOfUse\Persistence;

use Ultra\MappedTable\MappedColumn;
use Ultra\MappedTable\MappedRowSetDefinition;
use Ultra\MappedTable\MappedRowSetMapping;

final class AcceptedTermsDefinition implements MappedRowSetDefinition
{
    public function mapping(): MappedRowSetMapping
    {
        return new MappedRowSetMapping(
            'user_terms_of_use_acceptance',
            [
                'user_id' => new MappedColumn('string'),
                'accepted_terms_id' => new MappedColumn('string'),
                'accepted_terms_url' => new MappedColumn('string'),
            ],
            ['user_id'],
        );
    }
}
