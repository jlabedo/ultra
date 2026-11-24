<?php

declare(strict_types=1);

namespace Ultra\StateStore\Tests\Support;

use Ultra\MappedTable\SchemaMappedRowSet;
use Ultra\Schema\Attribute\DefineSchema;
use Ultra\Schema\ColumnDefinition;
use Ultra\Schema\ColumnType;
use Ultra\Schema\ForeignKeyDefinition;
use Ultra\Schema\SchemaDefinition;
use Ultra\Schema\TableDefinition;

final class StateStoreSchema
{
    public const string ORDERS = 'orders';
    public const string ORDER_LINES = 'order_lines';
    public const string COMPOSITE_ORDERS = 'composite_orders';
    public const string COMPOSITE_ORDER_LINES = 'composite_order_lines';
    public const string COUNTERS = 'counters';
    public const string ARCHIVED_COUNTERS = 'archived_counters';
    public const string TERMS_ACCEPTANCES = 'terms_acceptances';
    public const string CONVENTIONAL_NAMES = 'conventional_names';

    #[DefineSchema]
    public static function definition(): SchemaDefinition
    {
        return new SchemaDefinition([
            new TableDefinition(
                self::ORDERS,
                columns: [
                    new ColumnDefinition('order_id', type: ColumnType::Text),
                    new ColumnDefinition('status', type: ColumnType::Text),
                    new ColumnDefinition('note', type: ColumnType::Text, nullable: true),
                    new ColumnDefinition('shipping_street', type: ColumnType::Text),
                    new ColumnDefinition('shipping_zip', type: ColumnType::Text),
                ],
                primaryKey: ['order_id'],
            ),
            new TableDefinition(
                self::ORDER_LINES,
                columns: [
                    new ColumnDefinition('order_id', type: ColumnType::Text),
                    new ColumnDefinition('line_id', type: ColumnType::Text),
                    new ColumnDefinition('sku', type: ColumnType::Text),
                    new ColumnDefinition('quantity', type: ColumnType::Int32),
                ],
                primaryKey: ['order_id', 'line_id'],
                foreignKeys: ['owner' => new ForeignKeyDefinition(self::ORDERS, ['order_id' => 'order_id'])],
            ),
            new TableDefinition(
                self::COMPOSITE_ORDERS,
                columns: [
                    new ColumnDefinition('tenant_id', type: ColumnType::Text),
                    new ColumnDefinition('order_number', type: ColumnType::Text),
                ],
                primaryKey: ['tenant_id', 'order_number'],
            ),
            new TableDefinition(
                self::COMPOSITE_ORDER_LINES,
                columns: [
                    new ColumnDefinition('tenant_id', type: ColumnType::Text),
                    new ColumnDefinition('order_number', type: ColumnType::Text),
                    new ColumnDefinition('line_sku', type: ColumnType::Text),
                    new ColumnDefinition('line_variant', type: ColumnType::Text),
                    new ColumnDefinition('quantity', type: ColumnType::Int32),
                ],
                primaryKey: ['tenant_id', 'order_number', 'line_sku', 'line_variant'],
                foreignKeys: ['owner' => new ForeignKeyDefinition(self::COMPOSITE_ORDERS, [
                    'tenant_id' => 'tenant_id',
                    'order_number' => 'order_number',
                ])],
            ),
            new TableDefinition(
                self::COUNTERS,
                columns: [
                    new ColumnDefinition('id', type: ColumnType::Int32),
                    new ColumnDefinition('count', type: ColumnType::Int32),
                ],
                primaryKey: ['id'],
            ),
            new TableDefinition(
                self::ARCHIVED_COUNTERS,
                columns: [
                    new ColumnDefinition('id', type: ColumnType::Int32),
                    new ColumnDefinition('count', type: ColumnType::Int32),
                ],
                primaryKey: ['id'],
            ),
            new TableDefinition(
                self::TERMS_ACCEPTANCES,
                columns: [
                    new ColumnDefinition('identity_id', type: ColumnType::Text),
                    new ColumnDefinition('terms_id', type: ColumnType::Text),
                    new ColumnDefinition('accepted_at', type: ColumnType::Text),
                ],
                primaryKey: ['identity_id', 'terms_id'],
            ),
            new TableDefinition(
                self::CONVENTIONAL_NAMES,
                columns: [
                    new ColumnDefinition('record_id', type: ColumnType::Text),
                    new ColumnDefinition('http_status', type: ColumnType::Text),
                    new ColumnDefinition('DisplayName', type: ColumnType::Text),
                    new ColumnDefinition('shipping_postal_code', type: ColumnType::Text),
                    new ColumnDefinition('shipping_url_label', type: ColumnType::Text),
                ],
                primaryKey: ['record_id'],
            ),
        ]);
    }

    public static function selection(string $table): SchemaMappedRowSet
    {
        return match ($table) {
            self::ORDER_LINES, self::COMPOSITE_ORDER_LINES => new SchemaMappedRowSet($table, owner: 'owner'),
            default => new SchemaMappedRowSet($table),
        };
    }
}
