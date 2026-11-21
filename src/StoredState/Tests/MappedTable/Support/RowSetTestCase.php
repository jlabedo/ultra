<?php

declare(strict_types=1);

namespace Ultra\MappedTable\Tests\Support;

use PHPUnit\Framework\TestCase;
use Ultra\MappedTable\InMemoryRowDatabase;
use Ultra\MappedTable\MappedColumn;
use Ultra\MappedTable\MappedKey;
use Ultra\MappedTable\MappedRowSet;
use Ultra\MappedTable\MappedRowSetMapping;
use Ultra\MappedTable\PostgresMappedColumnType;
use Ultra\Testing\PostgresConnectionSupport;

abstract class RowSetTestCase extends TestCase
{
    /** @var list<RowSetFixture> */
    private array $fixtures = [];

    /** @return iterable<string, array{string}> */
    public static function backends(): iterable
    {
        yield 'in memory' => ['memory'];
        yield 'Postgres' => ['postgres'];
    }

    /** @param list<string> $keyColumns @param array<string, PostgresMappedColumnType> $columnTypes */
    protected function fixture(
        string $backend = 'memory',
        array $keyColumns = ['id'],
        ?MappedKey $scope = null,
        array $columnTypes = [],
        bool $allowAbsentObservations = true,
    ): RowSetFixture {
        if ('postgres' === $backend) {
            $dsn = getenv('ULTRA_TEST_POSTGRES_DSN');
            if (false === $dsn || '' === $dsn) {
                self::markTestSkipped('Set ULTRA_TEST_POSTGRES_DSN to run the Postgres guarantee cases.');
            }
            $storage = PostgresConnectionSupport::sharedConnection($dsn);
        } else {
            $storage = new InMemoryRowDatabase();
        }
        $fixture = new RowSetFixture(
            $storage,
            'ultra_guarantees_' . bin2hex(random_bytes(4)),
            $keyColumns,
            $scope,
            $columnTypes,
            $allowAbsentObservations,
        );
        $this->fixtures[] = $fixture;
        return $fixture;
    }

    /** @return array{RowSetFixture, MappedRowSet} */
    protected function ownedRows(string $backend): array
    {
        $keys = ['tenant', 'owner', 'id'];
        $fixture = $this->fixture($backend, $keys);

        return [
            $fixture,
            $fixture->defined(
                new MappedRowSetMapping(
                    $fixture->tableName,
                    [
                        'tenant' => new MappedColumn('string'),
                        'owner' => new MappedColumn('string'),
                        'id' => new MappedColumn('string'),
                        'status' => new MappedColumn('string', nullable: true),
                        'note' => new MappedColumn('string', nullable: true),
                    ],
                    $keys,
                    ownerColumns: ['tenant', 'owner'],
                ),
            ),
        ];
    }

    protected function tearDown(): void
    {
        try {
            foreach (array_reverse($this->fixtures) as $fixture) {
                $fixture->close();
            }
        } finally {
            $this->fixtures = [];
            parent::tearDown();
        }
    }
}
