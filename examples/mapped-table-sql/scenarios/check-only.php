<?php

declare(strict_types=1);

use MappedTableSqlExample\Scenario;
use Ultra\MappedTable\MappedRowSet;

return new Scenario(static function (MappedRowSet $rows): void {
    $pending = $rows->withKey('a');
    $row = $pending->observe(['status']);
    if (null === $row) {
        throw new RuntimeException('The prepared row is missing.');
    }
    $pending->setColumn('status', $row['status']);
    $rows->withKey('b')->expectPresent();
    $rows->withKey('missing')->expectAbsent();
}, initialRows: [
    ['id' => 'a', 'status' => 'active'],
    ['id' => 'b', 'status' => 'pending'],
]);
