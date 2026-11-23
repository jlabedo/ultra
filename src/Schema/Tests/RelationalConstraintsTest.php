<?php

declare(strict_types=1);

namespace Ultra\Schema\Tests;

use Doctrine\DBAL\Exception\ForeignKeyConstraintViolationException;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use PHPUnit\Framework\Attributes\CoversNamespace;
use Ultra\Schema\ColumnDefinition;
use Ultra\Schema\ColumnType;
use Ultra\Schema\ForeignKeyDefinition;
use Ultra\Schema\SchemaDefinition;
use Ultra\Schema\SchemaReconciler;
use Ultra\Schema\TableDefinition;
use Ultra\Schema\Tests\Support\MigrationTestCase;

/** @internal */
#[CoversNamespace('Ultra\Schema')]
final class RelationalConstraintsTest extends MigrationTestCase
{
    public function test_composite_keys_resolve_declared_ids_to_physical_names_and_enforce_references(): void
    {
        $parent = new TableDefinition(
            'parent',
            'workshops',
            [
                new ColumnDefinition('site', 'site_id', ColumnType::Text),
                new ColumnDefinition('number', 'workshop_no', ColumnType::Int32),
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
        $db->insert('workshops', ['site_id' => 'north', 'workshop_no' => 3]);
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
