<?php

declare(strict_types=1);

namespace App\Booking\StateStore\Persistence;

use Ultra\Schema\Attribute\DefineSchema;
use Ultra\Schema\ColumnDefinition;
use Ultra\Schema\ColumnType;
use Ultra\Schema\ForeignKeyDefinition;
use Ultra\Schema\ForeignKeyTiming;
use Ultra\Schema\IndexDefinition;
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
                    new ColumnDefinition('start_at', type: ColumnType::TimestampWithTimeZone),
                    new ColumnDefinition('end_at', type: ColumnType::TimestampWithTimeZone),
                    new ColumnDefinition('status', type: ColumnType::Text),
                    new ColumnDefinition('charged', type: ColumnType::Boolean),
                ],
                indexes: [
                    new IndexDefinition('cw_ss_bookings_room_id_start_at_idx', columns: ['room_id', 'start_at']),
                    new IndexDefinition('cw_ss_bookings_company_id_start_at_idx', columns: ['company_id', 'start_at']),
                ],
                primaryKey: ['booking_id'],
                foreignKeys: [
                    'cw_ss_bookings_room_id_fkey' => new ForeignKeyDefinition(
                        self::ROOMS,
                        ['room_id' => 'room_id'],
                        ForeignKeyTiming::InitiallyDeferred,
                    ),
                    'cw_ss_bookings_member_id_fkey' => new ForeignKeyDefinition(
                        self::MEMBERS,
                        ['member_id' => 'member_id'],
                        ForeignKeyTiming::InitiallyDeferred,
                    ),
                    'cw_ss_bookings_company_id_fkey' => new ForeignKeyDefinition(
                        self::COMPANIES,
                        ['company_id' => 'company_id'],
                        ForeignKeyTiming::InitiallyDeferred,
                    ),
                ],
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

    /** @return list<string> */
    public static function rowSets(): array
    {
        return [
            self::BOOKINGS,
            self::COMPANIES,
            self::MEMBER_EMAILS,
            self::MEMBERS,
            self::ROOMS,
        ];
    }
}
