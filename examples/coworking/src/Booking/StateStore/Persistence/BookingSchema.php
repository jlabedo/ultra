<?php

declare(strict_types=1);

namespace App\Booking\StateStore\Persistence;

use Ultra\MappedTable\PostgresMappedColumnType;
use Ultra\MappedTable\SchemaMappedRowSet;
use Ultra\Schema\Attribute\DefineSchema;
use Ultra\Schema\ColumnDefinition;
use Ultra\Schema\ColumnType;
use Ultra\Schema\SchemaDefinition;
use Ultra\Schema\TableDefinition;

final class BookingSchema
{
    public const string BOOKINGS = 'cw_ss_bookings';
    public const string COMPANIES = 'cw_ss_companies';
    public const string MEMBER_EMAILS = 'cw_ss_member_emails';
    public const string MEMBERS = 'cw_ss_members';
    public const string ROOMS = 'cw_ss_rooms';

    #[DefineSchema]
    public static function definition(): SchemaDefinition
    {
        return new SchemaDefinition([
            new TableDefinition(
                self::BOOKINGS,
                columns: [
                    new ColumnDefinition('booking_id', type: ColumnType::Text),
                    new ColumnDefinition('room_id', type: ColumnType::Text),
                    new ColumnDefinition('member_id', type: ColumnType::Text),
                    new ColumnDefinition('company_id', type: ColumnType::Text),
                    new ColumnDefinition('start_at', type: 'timestamptz'),
                    new ColumnDefinition('end_at', type: 'timestamptz'),
                    new ColumnDefinition('status', type: ColumnType::Text),
                    new ColumnDefinition('charged', type: ColumnType::Boolean),
                ],
                primaryKey: ['booking_id'],
            ),
            new TableDefinition(
                self::COMPANIES,
                columns: [
                    new ColumnDefinition('company_id', type: ColumnType::Text),
                    new ColumnDefinition('name', type: ColumnType::Text),
                    new ColumnDefinition('monthly_minutes', type: ColumnType::Int32),
                ],
                primaryKey: ['company_id'],
            ),
            new TableDefinition(
                self::MEMBER_EMAILS,
                columns: [
                    new ColumnDefinition('email', type: ColumnType::Text),
                    new ColumnDefinition('member_id', type: ColumnType::Text),
                ],
                primaryKey: ['email'],
            ),
            new TableDefinition(
                self::MEMBERS,
                columns: [
                    new ColumnDefinition('member_id', type: ColumnType::Text),
                    new ColumnDefinition('company_id', type: ColumnType::Text),
                    new ColumnDefinition('email', type: ColumnType::Text),
                ],
                primaryKey: ['member_id'],
            ),
            new TableDefinition(
                self::ROOMS,
                columns: [
                    new ColumnDefinition('room_id', type: ColumnType::Text),
                    new ColumnDefinition('name', type: ColumnType::Text),
                ],
                primaryKey: ['room_id'],
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
            new SchemaMappedRowSet(self::MEMBER_EMAILS),
            new SchemaMappedRowSet(self::MEMBERS),
            new SchemaMappedRowSet(self::ROOMS),
        ];
    }
}
