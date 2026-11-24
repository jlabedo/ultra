<?php

declare(strict_types=1);

namespace App\Booking\StateStoreDirect\Persistence;

use Ultra\MappedTable\PostgresMappedColumnType;
use Ultra\MappedTable\SchemaMappedRowSet;
use Ultra\Schema\Attribute\DefineSchema;
use Ultra\Schema\ColumnDefinition;
use Ultra\Schema\ColumnType;
use Ultra\Schema\SchemaDefinition;
use Ultra\Schema\TableDefinition;

final class BookingSchema
{
    public const string BOOKINGS = 'cw_direct_bookings';
    public const string COMPANIES = 'cw_direct_companies';
    public const string MEMBERS = 'cw_direct_members';
    public const string ROOMS = 'cw_direct_rooms';

    #[DefineSchema]
    public static function definition(): SchemaDefinition
    {
        return new SchemaDefinition([
            new TableDefinition(
                self::BOOKINGS,
                columns: [
                    new ColumnDefinition('id', type: ColumnType::Text),
                    new ColumnDefinition('room_id', type: ColumnType::Text),
                    new ColumnDefinition('member_id', type: ColumnType::Text),
                    new ColumnDefinition('company_id', type: ColumnType::Text),
                    new ColumnDefinition('start_at', type: 'timestamptz'),
                    new ColumnDefinition('end_at', type: 'timestamptz'),
                    new ColumnDefinition('status', type: ColumnType::Text),
                    new ColumnDefinition('charged', type: ColumnType::Boolean),
                ],
                primaryKey: ['id'],
            ),
            new TableDefinition(
                self::COMPANIES,
                columns: [
                    new ColumnDefinition('id', type: ColumnType::Text),
                    new ColumnDefinition('name', type: ColumnType::Text),
                    new ColumnDefinition('monthly_minutes', type: ColumnType::Int32),
                ],
                primaryKey: ['id'],
            ),
            new TableDefinition(
                self::MEMBERS,
                columns: [
                    new ColumnDefinition('id', type: ColumnType::Text),
                    new ColumnDefinition('company_id', type: ColumnType::Text),
                    new ColumnDefinition('email', type: ColumnType::Text),
                ],
                primaryKey: ['id'],
            ),
            new TableDefinition(
                self::ROOMS,
                columns: [
                    new ColumnDefinition('id', type: ColumnType::Text),
                    new ColumnDefinition('name', type: ColumnType::Text),
                ],
                primaryKey: ['id'],
            ),
        ]);
    }

    /** @return list<SchemaMappedRowSet> */
    public static function rowSets(): array
    {
        return [
            new SchemaMappedRowSet(
                self::BOOKINGS,
                representations: ['start_at' => 'string', 'end_at' => 'string'],
                postgresColumnTypes: [
                    'start_at' => PostgresMappedColumnType::TimestampWithTimeZone,
                    'end_at' => PostgresMappedColumnType::TimestampWithTimeZone,
                ],
            ),
            new SchemaMappedRowSet(self::COMPANIES),
            new SchemaMappedRowSet(self::MEMBERS),
            new SchemaMappedRowSet(self::ROOMS),
        ];
    }
}
