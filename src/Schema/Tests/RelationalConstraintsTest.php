<?php

declare(strict_types=1);

namespace Ultra\Schema\Tests;

use Doctrine\DBAL\Exception\DriverException;
use Doctrine\DBAL\Exception\ForeignKeyConstraintViolationException;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use PHPUnit\Framework\Attributes\CoversNamespace;
use Ultra\Schema\ColumnDefinition;
use Ultra\Schema\ColumnType;
use Ultra\Schema\ForeignKeyDefinition;
use Ultra\Schema\ForeignKeyTiming;
use Ultra\Schema\SchemaDefinition;
use Ultra\Schema\SchemaReconciler;
use Ultra\Schema\TableDefinition;
use Ultra\Schema\Tests\Support\MigrationTestCase;

/** @internal */
#[CoversNamespace('Ultra\Schema')]
final class RelationalConstraintsTest extends MigrationTestCase
{
    public function test_named_table_checks_from_fragments_enforce_rules_across_columns(): void
    {
        $schema = SchemaDefinition::merge(
            new SchemaDefinition([new TableDefinition('limits', columns: [
                new ColumnDefinition('lower_bound', type: ColumnType::Int32),
                new ColumnDefinition('upper_bound', type: ColumnType::Int32),
            ])]),
            new SchemaDefinition([new TableDefinition('limits', checks: [
                'ordered"bounds' => 'lower_bound <= upper_bound',
            ])]),
        );
        $this->execute(new SchemaReconciler()->reconcile(new SchemaDefinition([]), $schema));
        self::assertSame('ordered"bounds', $this->connection->fetchOne(<<<'SQL'
            SELECT conname FROM pg_constraint WHERE conrelid = 'limits'::regclass AND contype = 'c'
            SQL));
        $this->connection->insert('limits', ['lower_bound' => 1, 'upper_bound' => 2]);
        try {
            $this->connection->transactional(static fn($db) => $db->insert('limits', [
                'lower_bound' => 3,
                'upper_bound' => 2,
            ]));
            self::fail('PostgreSQL must reject the unordered bounds.');
        } catch (DriverException $exception) {
            self::assertSame('23514', $exception->getSQLState());
        }
    }

    public function test_changing_foreign_key_timing_updates_when_postgres_checks_the_reference(): void
    {
        $parent = new TableDefinition(
            'parents',
            columns: [
                new ColumnDefinition('id', type: ColumnType::Int32),
            ],
            primaryKey: ['id'],
        );
        $schema = static fn(ForeignKeyTiming $timing): SchemaDefinition => new SchemaDefinition([
            $parent,
            new TableDefinition(
                'children',
                columns: [
                    new ColumnDefinition('id', type: ColumnType::Int32),
                    new ColumnDefinition('parent_id', type: ColumnType::Int32),
                ],
                primaryKey: ['id'],
                foreignKeys: [
                    'parent_reference' => new ForeignKeyDefinition('parents', ['parent_id' => 'id'], $timing),
                ],
            ),
        ]);
        $reconciler = new SchemaReconciler();
        $immediate = $schema(ForeignKeyTiming::InitiallyImmediate);
        $deferred = $schema(ForeignKeyTiming::InitiallyDeferred);
        $timing = "SELECT condeferrable, condeferred FROM pg_constraint
            WHERE conrelid = 'children'::regclass AND conname = 'parent_reference'";
        $this->execute($reconciler->reconcile(new SchemaDefinition([]), $immediate));
        self::assertSame([true, false], $this->connection->fetchNumeric($timing));

        $this->execute($reconciler->reconcile($immediate, $deferred));
        self::assertSame([true, true], $this->connection->fetchNumeric($timing));
        $this->connection->insert('children', ['id' => 1, 'parent_id' => 2]);
        $this->connection->insert('parents', ['id' => 2]);
        $this->connection->executeStatement('SET CONSTRAINTS parent_reference IMMEDIATE');
        self::assertSame(2, $this->connection->fetchOne('SELECT parent_id FROM children WHERE id = 1'));
    }

    public function test_composite_keys_resolve_declared_ids_to_physical_names_and_enforce_references(): void
    {
        $parent = new TableDefinition(
            'parent',
            'workshops',
            [
                new ColumnDefinition('site', 'site_id', ColumnType::Text),
                new ColumnDefinition('number', 'workshop_no', ColumnType::Int32),
                new ColumnDefinition('budget', type: ColumnType::Int64),
                new ColumnDefinition('open', type: ColumnType::Boolean),
            ],
            primaryKey: ['site', 'number'],
        );
        $child = new TableDefinition(
            'child',
            'reservations',
            [
                new ColumnDefinition('site', 'workshop_site', ColumnType::Text),
                new ColumnDefinition('workshop', 'workshop_no', ColumnType::Int32),
                new ColumnDefinition('reference', 'booking_ref', ColumnType::Text),
            ],
            primaryKey: ['site', 'workshop', 'reference'],
            foreignKeys: [
                'workshop_owner' => new ForeignKeyDefinition('parent', ['site' => 'site', 'workshop' => 'number']),
            ],
        );
        $schema = new SchemaDefinition([$child, $parent]);
        $reconciler = new SchemaReconciler();
        $this->execute($reconciler->reconcile(new SchemaDefinition([]), $schema));

        $db = $this->connection;
        $db->executeStatement("INSERT INTO workshops VALUES ('north', 3, 3000000000, true)");
        self::assertSame(3000000000, (int) $db->fetchOne('SELECT budget FROM workshops WHERE open'));
        $booking = ['workshop_site' => 'north', 'workshop_no' => 3, 'booking_ref' => 'team'];
        $db->insert('reservations', $booking);

        try {
            $db->transactional(static fn() => $db->insert('reservations', $booking));
            self::fail('The composite primary key must prevent duplicate reservations.');
        } catch (UniqueConstraintViolationException) {
            self::assertSame(1, (int) $db->fetchOne('SELECT COUNT(*) FROM reservations'));
        }

        try {
            $db->transactional(static fn() => $db->insert('reservations', [
                'workshop_site' => 'south',
                ...array_slice($booking, 1),
            ]));
            self::fail('The composite foreign key must prevent reservations for an absent workshop.');
        } catch (ForeignKeyConstraintViolationException) {
            self::assertSame(1, (int) $db->fetchOne('SELECT COUNT(*) FROM reservations'));
        }

        $this->execute($reconciler->reconcile($schema, new SchemaDefinition([$parent, $child])));
        $this->execute($reconciler->reconcile(new SchemaDefinition([$parent, $child]), new SchemaDefinition([])));
        self::assertNull($db->fetchOne("SELECT to_regclass('workshops')"));
        self::assertNull($db->fetchOne("SELECT to_regclass('reservations')"));
    }
}
