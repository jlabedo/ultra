<?php

declare(strict_types=1);

use MappedTableSqlExample\Scenario;
use Ultra\MappedTable\MappedRowSet;

return new Scenario(
    static function (MappedRowSet $rows): void {
        $row = $rows->row('a', ['status']);
        if (false === $row) {
            throw new RuntimeException('The prepared row is missing.');
        }
        $rows->withKey('a')->expectColumnValue('status', $row['status'])->setColumn('status', $row['status']);
        $rows->withKey('b')->expectPresent();
        $rows->withKey('missing')->expectAbsent();
    },
    initialRows: [
        ['id' => 'a', 'status' => 'active'],
        ['id' => 'b', 'status' => 'pending'],
    ],
);
