<?php

declare(strict_types=1);

namespace ComplianceExample;

use Ultra\Schema\Attribute\DefineSchema;
use Ultra\Schema\ColumnDefinition;
use Ultra\Schema\ColumnType;
use Ultra\Schema\IndexDefinition;
use Ultra\Schema\SchemaDefinition;
use Ultra\Schema\TableDefinition;

final class ComplianceSchema
{
    public const string EVIDENCE = 'compliance_evidence';
    public const string QUALIFICATIONS = 'compliance_qualifications';
    public const string ELIGIBILITY = 'compliance_eligibility';

    #[DefineSchema]
    public static function definition(): SchemaDefinition
    {
        return new SchemaDefinition([
            new TableDefinition(
                self::EVIDENCE,
                columns: [
                    new ColumnDefinition('account_id', type: ColumnType::Text),
                    new ColumnDefinition('subject_id', type: ColumnType::Text),
                    new ColumnDefinition('evidence_id', type: ColumnType::Text),
                    new ColumnDefinition('document_type', type: ColumnType::Text),
                ],
                indexes: [
                    new IndexDefinition('compliance_evidence_by_type', columns: [
                        'account_id',
                        'subject_id',
                        'document_type',
                    ]),
                ],
                primaryKey: ['account_id', 'subject_id', 'evidence_id'],
            ),
            new TableDefinition(
                self::QUALIFICATIONS,
                columns: [
                    new ColumnDefinition('account_id', type: ColumnType::Text),
                    new ColumnDefinition('qualification_id', type: ColumnType::Text),
                    new ColumnDefinition('requirements', type: ColumnType::Jsonb),
                ],
                primaryKey: ['account_id', 'qualification_id'],
            ),
            new TableDefinition(
                self::ELIGIBILITY,
                columns: [
                    new ColumnDefinition('account_id', type: ColumnType::Text),
                    new ColumnDefinition('subject_id', type: ColumnType::Text),
                    new ColumnDefinition('qualification_id', type: ColumnType::Text),
                ],
                indexes: [
                    new IndexDefinition('compliance_eligibility_by_qualification', columns: [
                        'account_id',
                        'qualification_id',
                        'subject_id',
                    ]),
                ],
                primaryKey: ['account_id', 'subject_id', 'qualification_id'],
            ),
        ]);
    }

    /** @return list<string> */
    public static function rowSets(): array
    {
        return [
            self::EVIDENCE,
            self::QUALIFICATIONS,
        ];
    }
}
