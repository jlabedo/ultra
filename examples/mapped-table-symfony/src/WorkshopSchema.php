<?php

declare(strict_types=1);

namespace MappedTableSymfonyExample;

use Ultra\Schema\Attribute\DefineSchema;
use Ultra\Schema\ColumnDefinition;
use Ultra\Schema\ForeignKeyDefinition;
use Ultra\Schema\SchemaDefinition;
use Ultra\Schema\TableDefinition;
use Ultra\Sql\ColumnType;

final class WorkshopSchema
{
    public const string WORKSHOPS = 'workshop.workshops';
    public const string RESERVATIONS = 'workshop.reservations';
    public const string WORKSHOP_REFERENCE = 'reservations_workshop_fk';

    #[DefineSchema]
    public static function definition(): SchemaDefinition
    {
        return new SchemaDefinition([
            new TableDefinition(
                self::WORKSHOPS,
                'workshops',
                [
                    new ColumnDefinition('id', type: ColumnType::Text),
                    new ColumnDefinition('title', type: ColumnType::Text),
                    new ColumnDefinition('capacity', type: ColumnType::Int32, constraints: ['CHECK (capacity > 0)']),
                ],
                primaryKey: ['id'],
            ),
            new TableDefinition(
                self::RESERVATIONS,
                'reservations',
                [
                    new ColumnDefinition('workshop', 'workshop_id', ColumnType::Text),
                    new ColumnDefinition('reference', type: ColumnType::Text),
                    new ColumnDefinition('seats', type: ColumnType::Int32, constraints: ['CHECK (seats > 0)']),
                ],
                primaryKey: ['workshop', 'reference'],
                foreignKeys: [
                    self::WORKSHOP_REFERENCE => new ForeignKeyDefinition(self::WORKSHOPS, ['workshop' => 'id']),
                ],
            ),
        ]);
    }
}
