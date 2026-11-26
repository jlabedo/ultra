<?php

declare(strict_types=1);

namespace App\Projection;

use Ultra\MappedTable\Attribute\DefineMappedRowSet;
use Ultra\MappedTable\MappedRowSetDefinition;
use Ultra\MappedTable\Schema\SchemaRowSet;
use Ultra\Schema\Attribute\DefineSchema;
use Ultra\Schema\ColumnDefinition;
use Ultra\Schema\ColumnType;
use Ultra\Schema\SchemaDefinition;
use Ultra\Schema\TableDefinition;

final class OrderSummarySchema
{
    public const string ORDER_SUMMARY = 'order_summary';

    #[DefineSchema]
    public static function definition(): SchemaDefinition
    {
        return new SchemaDefinition([
            new TableDefinition(
                self::ORDER_SUMMARY,
                columns: [
                    new ColumnDefinition('id', type: ColumnType::Text),
                    new ColumnDefinition('customer', type: ColumnType::Text),
                    new ColumnDefinition('status', type: ColumnType::Text),
                ],
                primaryKey: ['id'],
            ),
        ]);
    }

    #[DefineMappedRowSet(self::ORDER_SUMMARY, unitOfWork: 'order_summary.persistence')]
    public static function rows(): MappedRowSetDefinition
    {
        return SchemaRowSet::fromTable(self::definition(), self::ORDER_SUMMARY);
    }
}
