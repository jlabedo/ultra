<?php

declare(strict_types=1);

namespace ComplianceExample;

use Ultra\MappedTable\MappedColumn;
use Ultra\MappedTable\MappedRowSetDefinition;
use Ultra\MappedTable\MappedRowSetMapping;
use Ultra\MappedTable\PostgresMappedColumnType;
use Ultra\MappedTable\PostgresMappedRowSetConfiguration;

final class QualificationRowsDefinition implements MappedRowSetDefinition, PostgresMappedRowSetConfiguration
{
    public function mapping(): MappedRowSetMapping
    {
        return new MappedRowSetMapping(
            'compliance_qualifications',
            [
                'account_id' => new MappedColumn('string'),
                'qualification_id' => new MappedColumn('string'),
                'requirements' => new MappedColumn('array'),
            ],
            ['account_id', 'qualification_id'],
        );
    }

    public function postgresColumnTypes(): array
    {
        return ['requirements' => PostgresMappedColumnType::Jsonb];
    }
}
