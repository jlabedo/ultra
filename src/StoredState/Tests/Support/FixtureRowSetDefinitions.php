<?php

declare(strict_types=1);

namespace Ultra\StateStore\Tests\Support;

use Ultra\MappedTable\MappedColumn;
use Ultra\MappedTable\MappedRowSetDefinition;
use Ultra\MappedTable\MappedRowSetMapping;
use Ultra\MappedTable\MappedRowSetOwner;

abstract class FixtureRowSetDefinition implements MappedRowSetDefinition
{
    protected const string TABLE = '';

    /** @var array<string, string> */
    protected const array KEY = [];

    /** @var array<string, string> */
    protected const array COLUMNS = [];

    /** @var class-string<MappedRowSetDefinition>|null */
    protected const ?string OWNER = null;

    /** @var array<string, string> */
    protected const array OWNER_COLUMNS = [];

    final public function mapping(): MappedRowSetMapping
    {
        $columns = array_map(
            static fn(string $type): MappedColumn => new MappedColumn($type),
            static::KEY + static::COLUMNS,
        );

        return new MappedRowSetMapping(
            static::TABLE,
            $columns,
            array_keys(static::KEY),
            owner: null === static::OWNER ? null : new MappedRowSetOwner(static::OWNER, static::OWNER_COLUMNS),
        );
    }
}

final class CompositeOrdersDefinition extends FixtureRowSetDefinition
{
    protected const string TABLE = 'composite_orders';
    protected const array KEY = ['tenant_id' => 'string', 'order_number' => 'string'];
}

final class CompositeOrderLinesDefinition extends FixtureRowSetDefinition
{
    protected const string TABLE = 'composite_order_lines';
    protected const array KEY = [
        'tenant_id' => 'string',
        'order_number' => 'string',
        'line_sku' => 'string',
        'line_variant' => 'string',
    ];
    protected const array COLUMNS = ['quantity' => 'int'];
    protected const string OWNER = CompositeOrdersDefinition::class;
    protected const array OWNER_COLUMNS = ['tenant_id' => 'tenant_id', 'order_number' => 'order_number'];
}

final class CountersDefinition extends FixtureRowSetDefinition
{
    protected const string TABLE = 'counters';
    protected const array KEY = ['id' => 'int'];
    protected const array COLUMNS = ['count' => 'int'];
}

final class ArchivedCountersDefinition extends FixtureRowSetDefinition
{
    protected const string TABLE = 'archived_counters';
    protected const array KEY = ['id' => 'int'];
    protected const array COLUMNS = ['count' => 'int'];
}

final class TermsAcceptancesDefinition extends FixtureRowSetDefinition
{
    protected const string TABLE = 'terms_acceptances';
    protected const array KEY = ['identity_id' => 'string', 'terms_id' => 'string'];
    protected const array COLUMNS = ['accepted_at' => 'string'];
}

final class ConventionalNamesDefinition extends FixtureRowSetDefinition
{
    protected const string TABLE = 'conventional_names';
    protected const array KEY = ['record_id' => 'string'];
    protected const array COLUMNS = [
        'http_status' => 'string',
        'DisplayName' => 'string',
        'shipping_postal_code' => 'string',
        'shipping_url_label' => 'string',
    ];
}
