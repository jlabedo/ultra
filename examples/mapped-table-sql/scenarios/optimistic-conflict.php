<?php

declare(strict_types=1);

use MappedTableSqlExample\Scenario;
use Ultra\MappedTable\MappedRowSet;

return new Scenario(
    static function (MappedRowSet $rows): void {
        $rows->withKey('a')->expectColumnValue('status', 'pending')->setColumn('status', 'active');
        // A stale expectation fails after the first UPDATE, so that write must roll back.
        $rows->withKey('b')->expectColumnValue('status', 'pending')->setColumn('status', 'active');
        $rows->withKey('c')->expectColumnValue('status', 'pending')->setColumn('status', 'active');
    },
    initialRows: [
        ['id' => 'a', 'status' => 'pending'],
        ['id' => 'b', 'status' => 'changed'],
        ['id' => 'c', 'status' => 'pending'],
    ],
);
