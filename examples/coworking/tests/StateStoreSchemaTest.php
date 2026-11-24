<?php

declare(strict_types=1);

namespace App\Tests;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception\ForeignKeyConstraintViolationException;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;

/** @internal */
final class StateStoreSchemaTest extends CoworkingKernelTestCase
{
    public function test_foreign_keys_are_initially_deferred_and_checked_at_commit(): void
    {
        $connection = self::getContainer()->get(Connection::class);
        self::assertSame(
            [
                'cw_direct_bookings_company_id_fkey',
                'cw_direct_bookings_member_id_fkey',
                'cw_direct_bookings_room_id_fkey',
                'cw_direct_members_company_id_fkey',
                'cw_ss_bookings_company_id_fkey',
                'cw_ss_bookings_member_id_fkey',
                'cw_ss_bookings_room_id_fkey',
            ],
            $connection->fetchFirstColumn(<<<'SQL'
                SELECT conname FROM pg_constraint
                WHERE conrelid IN ('cw_direct_members'::regclass, 'cw_direct_bookings'::regclass, 'cw_ss_bookings'::regclass)
                  AND contype = 'f' AND condeferrable AND condeferred
                ORDER BY conname
                SQL),
        );

        $connection->transactional(static function (Connection $connection): void {
            $connection->insert('cw_direct_members', [
                'id' => 'ada',
                'company_id' => 'acme',
                'email' => 'ada@example.test',
            ]);
            $connection->insert('cw_direct_companies', ['id' => 'acme', 'name' => 'Acme', 'monthly_minutes' => 60]);
        });
        self::assertSame('acme', $connection->fetchOne("SELECT company_id FROM cw_direct_members WHERE id = 'ada'"));

        $connection->beginTransaction();
        try {
            $connection->insert('cw_direct_members', [
                'id' => 'orphan',
                'company_id' => 'missing',
                'email' => 'orphan@example.test',
            ]);
            try {
                $connection->commit();
                self::fail('PostgreSQL must reject the missing company at commit.');
            } catch (ForeignKeyConstraintViolationException) {
                self::assertFalse($connection->fetchOne("SELECT id FROM cw_direct_members WHERE id = 'orphan'"));
            }
        } finally {
            if ($connection->isTransactionActive()) {
                $connection->rollBack();
            }
        }
    }

    public function test_postgres_enforces_email_uniqueness_in_both_state_store_variants(): void
    {
        $connection = self::getContainer()->get(Connection::class);
        $connection->insert('cw_direct_companies', ['id' => 'acme', 'name' => 'Acme', 'monthly_minutes' => 60]);
        foreach ([
            ['cw_direct_members', 'id', ['company_id' => 'acme', 'email' => 'ada@example.test']],
            ['cw_ss_member_emails', 'member_id', ['email' => 'ada@example.test']],
        ] as [$table, $memberColumn, $fields]) {
            $connection->insert($table, [$memberColumn => 'ada', ...$fields]);
            try {
                $connection->insert($table, [$memberColumn => 'other', ...$fields]);
                self::fail(sprintf('PostgreSQL must reject a duplicate email in %s.', $table));
            } catch (UniqueConstraintViolationException) {
                self::assertSame('ada', $connection->fetchOne(
                    sprintf(
                        'SELECT %s FROM %s WHERE email = ?',
                        $connection->quoteSingleIdentifier($memberColumn),
                        $connection->quoteSingleIdentifier($table),
                    ),
                    [$fields['email']],
                ));
            }
        }
    }

    public function test_booking_tables_have_the_four_lookup_indexes_in_column_order(): void
    {
        $connection = self::getContainer()->get(Connection::class);
        self::assertSame(
            [
                ['cw_direct_bookings_company_id_start_at_idx', 'company_id', 'start_at'],
                ['cw_direct_bookings_room_id_start_at_idx',    'room_id',    'start_at'],
                ['cw_ss_bookings_company_id_start_at_idx',     'company_id', 'start_at'],
                ['cw_ss_bookings_room_id_start_at_idx',        'room_id',    'start_at'],
            ],
            $connection->fetchAllNumeric(<<<'SQL'
                SELECT index_class.relname, pg_get_indexdef(idx.indexrelid, 1, true), pg_get_indexdef(idx.indexrelid, 2, true)
                FROM pg_index idx
                JOIN pg_class index_class ON index_class.oid = idx.indexrelid
                WHERE idx.indrelid IN ('cw_direct_bookings'::regclass, 'cw_ss_bookings'::regclass)
                  AND NOT idx.indisprimary AND NOT idx.indisunique AND idx.indnatts = 2
                ORDER BY index_class.relname
                SQL),
        );
    }
}
