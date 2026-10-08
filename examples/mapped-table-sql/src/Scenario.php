<?php

declare(strict_types=1);

namespace MappedTableSqlExample;

use Doctrine\DBAL\Connection;
use Ultra\MappedTable\MappedRowSet;

/** A normal PHP callback and its isolated initial data. */
final readonly class Scenario
{
    /**
     * @param \Closure(MappedRowSet, Connection, string): void $work
     * @param list<array<string, mixed>> $initialRows
     * @param \Closure(Connection, string): void|null $prepare runs before seeding, e.g. to add a column
     */
    public function __construct(
        public \Closure $work,
        public array $initialRows = [],
        public ?\Closure $prepare = null,
    ) {}
}
