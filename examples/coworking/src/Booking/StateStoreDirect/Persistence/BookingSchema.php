<?php

declare(strict_types=1);

namespace App\Booking\StateStoreDirect\Persistence;

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
                    new ColumnDefinition('start_at', type: ColumnType::TimestampWithTimeZone),
                    new ColumnDefinition('end_at', type: ColumnType::TimestampWithTimeZone),
                    new ColumnDefinition('status', type: ColumnType::Text),
                    new ColumnDefinition('charged', type: ColumnType::Boolean),
                ],
                indexes: [
                    new IndexDefinition('cw_direct_bookings_room_id_start_at_idx', columns: ['room_id', 'start_at']),
                    new IndexDefinition('cw_direct_bookings_company_id_start_at_idx', columns: [
                        'company_id',
                        'start_at',
                    ]),
                ],
                primaryKey: ['id'],
                foreignKeys: [
                    'cw_direct_bookings_room_id_fkey' => new ForeignKeyDefinition(
                        self::ROOMS,
                        ['room_id' => 'id'],
                        ForeignKeyTiming::InitiallyDeferred,
                    ),
                    'cw_direct_bookings_member_id_fkey' => new ForeignKeyDefinition(
                        self::MEMBERS,
                        ['member_id' => 'id'],
                        ForeignKeyTiming::InitiallyDeferred,
                    ),
                    'cw_direct_bookings_company_id_fkey' => new ForeignKeyDefinition(
                        self::COMPANIES,
                        ['company_id' => 'id'],
                        ForeignKeyTiming::InitiallyDeferred,
                    ),
                ],
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
                    new ColumnDefinition('email', type: ColumnType::Text, constraints: ['UNIQUE']),
                ],
                primaryKey: ['id'],
                foreignKeys: [
                    'cw_direct_members_company_id_fkey' => new ForeignKeyDefinition(
                        self::COMPANIES,
                        ['company_id' => 'id'],
                        ForeignKeyTiming::InitiallyDeferred,
                    ),
                ],
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

    /** @return list<string> */
    public static function rowSets(): array
    {
        return [
            self::BOOKINGS,
            self::COMPANIES,
            self::MEMBERS,
            self::ROOMS,
        ];
    }
}
