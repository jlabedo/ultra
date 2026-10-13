<?php

declare(strict_types=1);

namespace Ultra\StateStore\Tests;

use PHPUnit\Framework\Attributes\DataProvider;
use Ultra\Mapper_Compiler\NormalizationShapeAnalyzer;
use Ultra\StateStore\StateMetadataFactory;
use Ultra\StateStore\Tests\Support\StateStoreTestCase;
use Ultra\UnitOfWork\Exception\ConcurrencyException;

/** @internal */
final class StateMappingTest extends StateStoreTestCase
{
    public function test_composite_identifier_uses_default_column_names_with_partial_overrides(): void
    {
        $analyzer = new NormalizationShapeAnalyzer();
        $factory = new StateMetadataFactory([
            ConventionTermsAcceptanceState::class => $analyzer->forProperty(new \ReflectionClass(
                ConventionTermsAcceptanceState::class,
            )->getProperty('acceptanceId')),
        ]);

        static::assertSame(
            [
                'identityId' => 'identity_id',
                'termsId' => 'DocumentVersion',
            ],
            $factory->create(ConventionTermsAcceptanceState::class)->idPropertyColumns,
        );
    }

    /** @param array<string, mixed> $shape */
    #[DataProvider('invalidIdentifierShapes')]
    public function test_identifiers_reject_unsupported_scalar_shapes(array $shape): void
    {
        $factory = new StateMetadataFactory([OrderState::class => $shape]);
        $this->expectException(\Ultra\StateStore\Exception\InvalidStateConfigurationException::class);
        $this->expectExceptionMessage('must normalize to int|string');

        $factory->create(OrderState::class);
    }

    /** @return iterable<string, array{array<string, mixed>}> */
    public static function invalidIdentifierShapes(): iterable
    {
        yield 'empty scalar types' => [['kind' => 'scalar', 'types' => []]];
        yield 'float identity' => [['kind' => 'scalar', 'types' => ['float']]];
        yield 'non-string type name' => [['kind' => 'scalar', 'types' => [42]]];
        yield 'object type name' => [['kind' => 'scalar', 'types' => [new \stdClass()]]];
    }

    public function test_embedded_value_object_is_saved_as_flat_columns_and_participates_in_optimistic_expectations(): void
    {
        [$database, $table, $store] = $this->newStore([OrderWithShippingAddressState::class]);
        $database->seedRow('orders', 'order_id', [
            'order_id' => 'order-123',
            'shipping_street' => '1 Main Street',
            'shipping_zip' => '75001',
        ]);

        $loaded = $store->load(OrderWithShippingAddressState::class, 'order-123');
        static::assertInstanceOf(OrderWithShippingAddressState::class, $loaded);

        $store->expect($loaded);
        $store->save(new OrderWithShippingAddressState('order-123', new ShippingAddress('2 Main Street', '75001')));

        static::assertSame(
            [
                'shipping_street' => '2 Main Street',
                'shipping_zip' => '75001',
            ],
            $table->row('order-123', ['shipping_street', 'shipping_zip']),
        );

        $database->writeRow('orders', 'order_id', [
            'order_id' => 'order-123',
            'shipping_street' => '1 Main Street',
            'shipping_zip' => '75002',
        ]);

        $this->expectException(ConcurrencyException::class);

        $this->flush($database, $table);
    }

    public static function stateRepresentations(): iterable
    {
        yield 'scalar identity and renamed nullable columns' => [
            new OrderState('order-123', 'pending', null),
            'order-123',
            'orders',
            ['order_id' => 'order-123'],
            ['status' => 'pending', 'note' => null],
        ];
        yield 'integer identity with conventional columns' => [
            new CounterState(42, 7),
            42,
            'counters',
            ['id' => 42],
            ['count' => 7],
        ];
        yield 'composite identity' => [
            new TermsAcceptanceState(new AcceptanceId('identity-1', 'terms-2026'), '2026-07-10T10:00:00Z'),
            new AcceptanceId('identity-1', 'terms-2026'),
            'terms_acceptances',
            ['identity_id' => 'identity-1', 'terms_id' => 'terms-2026'],
            ['accepted_at' => '2026-07-10T10:00:00Z'],
        ];
        yield 'conventional scalar identity, acronyms and embedded fields with explicit override' => [
            new ConventionState('one', 'ok', 'Display', new ConventionAddress('75001', 'Home')),
            'one',
            'conventional_names',
            ['record_id' => 'one'],
            [
                'http_status' => 'ok',
                'DisplayName' => 'Display',
                'shipping_postal_code' => '75001',
                'shipping_url_label' => 'Home',
            ],
        ];
        yield 'flattened embedded value with prefix and column rename' => [
            new OrderWithShippingAddressState('order-123', new ShippingAddress('1 Main Street', '75001')),
            'order-123',
            'orders',
            ['order_id' => 'order-123'],
            ['shipping_street' => '1 Main Street', 'shipping_zip' => '75001'],
        ];
    }

    /**
     * @param array<string, int|string> $key
     * @param array<string, int|string|null> $columns
     */
    #[DataProvider('stateRepresentations')]
    public function test_states_round_trip_through_their_physical_mapping(
        object $state,
        int|string|object $id,
        string $table,
        array $key,
        array $columns,
    ): void {
        [$database, , $store, $registry] = $this->newStore([$state::class]);
        static::assertNull($store->load($state::class, $id));
        static::assertFalse($store->exists($state::class, $id));
        static::assertFalse($registry->hasPendingChanges());
        static::assertFalse($store->observeExists($state::class, $id));
        static::assertNull($store->observe($state::class, $id));
        $store->save($state);
        static::assertTrue($store->exists($state::class, $id));
        static::assertEquals($state, $store->load($state::class, $id));
        $mappedKey = new \Ultra\MappedTable\MappedKey($key);
        static::assertFalse($database->rowByKey($table, $mappedKey));
        $this->flush($database, $registry);
        $registry->clear();
        static::assertSame($columns, $database->rowByKey($table, $mappedKey, array_keys($columns)));
        static::assertEquals($state, $store->load($state::class, $id));
        static::assertTrue($store->exists($state::class, $id));
        static::assertFalse($registry->hasPendingChanges());
        static::assertTrue($store->observeExists($state::class, $id));
        static::assertEquals($state, $store->observe($state::class, $id));
        if ($id instanceof AcceptanceId) {
            static::assertFalse($store->exists($state::class, new AcceptanceId('identity-2', $id->termsId)));
            static::assertFalse($store->observeExists($state::class, new AcceptanceId('identity-2', $id->termsId)));
        }
    }

    public function test_manual_row_sets_preserve_distinct_candidate_keys_for_the_same_table(): void
    {
        [$database, , $store, $registry] = $this->newStore([ContactByIdState::class, ContactByEmailState::class]);
        $database->seedRow('contacts', 'contact_id', [
            'contact_id' => 42,
            'email' => 'one@example.test',
            'name' => 'Alice',
        ]);

        static::assertEquals(
            new ContactByIdState(42, 'one@example.test', 'Alice'),
            $store->load(ContactByIdState::class, 42),
        );
        static::assertEquals(
            new ContactByEmailState('one@example.test', 42, 'Alice'),
            $store->observe(ContactByEmailState::class, 'one@example.test'),
        );
        $store->save(new ContactByEmailState('one@example.test', 42, 'Bob'));
        static::assertEquals(
            new ContactByEmailState('one@example.test', 42, 'Bob'),
            $store->load(ContactByEmailState::class, 'one@example.test'),
        );
        $this->flush($database, $registry);
        $registry->clear();

        static::assertSame(['name' => 'Bob'], $database->row('contacts', 'contact_id', 42, ['name']));
        static::assertEquals(
            new ContactByIdState(42, 'one@example.test', 'Bob'),
            $store->load(ContactByIdState::class, 42),
        );
        static::assertEquals(
            new ContactByEmailState('one@example.test', 42, 'Bob'),
            $store->load(ContactByEmailState::class, 'one@example.test'),
        );
    }

    public static function independentTableMappings(): iterable
    {
        yield 'same key names and identifier in different tables' => [
            new CounterState(42, 7),
            new ArchivedCounterState(42, 11),
            ['counters', ['id' => 42], ['count' => 7]],
            ['archived_counters', ['id' => 42], ['count' => 11]],
        ];
        yield 'ambiguous table and column names' => [
            new LeftBoundaryState('same', 'left'),
            new RightBoundaryState('same', 'right'),
            ['a', ['bc' => 'same'], ['value' => 'left']],
            ['ab', ['c' => 'same'], ['value' => 'right']],
        ];
    }

    /**
     * @param array{string, array<string, int|string>, array<string, int|string>} $leftRow
     * @param array{string, array<string, int|string>, array<string, int|string>} $rightRow
     */
    #[DataProvider('independentTableMappings')]
    public function test_registered_states_address_their_own_physical_table(
        object $left,
        object $right,
        array $leftRow,
        array $rightRow,
    ): void {
        [$database, , $store, $registry] = $this->newStore([$left::class, $right::class]);
        $store->save($left);
        $store->save($right);
        $this->flush($database, $registry);
        $registry->clear();
        static::assertEquals($left, $store->load($left::class, $left->id));
        static::assertEquals($right, $store->load($right::class, $right->id));
        foreach ([$leftRow, $rightRow] as [$table, $key, $columns]) {
            static::assertSame($columns, $database->rowByKey(
                $table,
                new \Ultra\MappedTable\MappedKey($key),
                array_keys($columns),
            ));
        }
    }

    public function test_identifier_validation_and_unknown_states_do_not_queue_work(): void
    {
        $fixture = $this->stateFixture('memory', [OrderState::class, CompositeOrderState::class, CounterState::class]);
        foreach ([
            [OrderState::class, 1],
            [CompositeOrderState::class, 'one'],
            [CounterState::class, '1'],
            [\stdClass::class, 'one'],
        ] as [$class, $id]) {
            foreach (['delete', 'exists'] as $operation) {
                try {
                    $fixture->store->$operation($class, $id);
                    static::fail('Invalid state identities must be rejected.');
                } catch (\InvalidArgumentException|\Ultra\StateStore\Exception\UnknownStateException) {
                }
            }
        }
        static::assertFalse($fixture->registry->hasPendingChanges());
    }

    #[DataProvider('backends')]
    public function test_deletion_does_not_require_a_hydratable_state(string $backend): void
    {
        $fixture = $this->stateFixture($backend, [OrderIdentityState::class, OrderWithLinesState::class]);
        $fixture->store->save(new OrderIdentityState('one'));
        $fixture->flush();
        $fixture->registry->clear();
        $fixture->store->delete(OrderWithLinesState::class, 'one');
        $fixture->flush();
        $fixture->registry->clear();
        static::assertFalse($fixture->store->observeExists(OrderIdentityState::class, 'one'));
    }
}
