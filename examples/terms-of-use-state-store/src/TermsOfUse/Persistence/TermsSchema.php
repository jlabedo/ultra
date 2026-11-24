<?php

declare(strict_types=1);

namespace App\TermsOfUse\Persistence;

use Ultra\MappedTable\SchemaMappedRowSet;
use Ultra\Schema\Attribute\DefineSchema;
use Ultra\Schema\ColumnDefinition;
use Ultra\Schema\ColumnType;
use Ultra\Schema\SchemaDefinition;
use Ultra\Schema\TableDefinition;

final class TermsSchema
{
    public const string ACCEPTED_TERMS = 'user_terms_of_use_acceptance';
    public const string CURRENT_TERMS = 'current_terms_of_use';

    #[DefineSchema]
    public static function definition(): SchemaDefinition
    {
        return new SchemaDefinition([
            new TableDefinition(
                self::ACCEPTED_TERMS,
                columns: [
                    new ColumnDefinition('user_id', type: ColumnType::Text),
                    new ColumnDefinition('accepted_terms_id', type: ColumnType::Text),
                    new ColumnDefinition('accepted_terms_url', type: ColumnType::Text),
                ],
                primaryKey: ['user_id'],
            ),
            new TableDefinition(
                self::CURRENT_TERMS,
                columns: [
                    new ColumnDefinition('state_id', type: ColumnType::Text),
                    new ColumnDefinition('terms_id', type: ColumnType::Text),
                    new ColumnDefinition('terms_url', type: ColumnType::Text),
                ],
                primaryKey: ['state_id'],
            ),
        ]);
    }

    /** @return list<SchemaMappedRowSet> */
    public static function rowSets(): array
    {
        return [
            new SchemaMappedRowSet(self::ACCEPTED_TERMS),
            new SchemaMappedRowSet(self::CURRENT_TERMS),
        ];
    }
}
