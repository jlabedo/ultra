<?php

declare(strict_types=1);

namespace ComplianceExample;

use Ultra\MappedTable\MappedColumn;
use Ultra\MappedTable\MappedRowSetDefinition;
use Ultra\MappedTable\MappedRowSetMapping;

final class EvidenceRowsDefinition implements MappedRowSetDefinition
{
    public function mapping(): MappedRowSetMapping
    {
        return new MappedRowSetMapping(
            'compliance_evidence',
            [
                'account_id' => new MappedColumn('string'),
                'subject_id' => new MappedColumn('string'),
                'evidence_id' => new MappedColumn('string'),
                'document_type' => new MappedColumn('string'),
            ],
            ['account_id', 'subject_id', 'evidence_id'],
        );
    }
}
