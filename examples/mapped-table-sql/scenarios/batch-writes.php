<?php

declare(strict_types=1);

use MappedTableSqlExample\Scenario;
use Ultra\MappedTable\MappedRowSet;

return new Scenario(static function (MappedRowSet $rows): void {
    $rows->withKey('a')->insert()->setColumn('status', 'draft');
    $rows->withKey('a')->setColumn('status', 'active');
    $rows->withKey('b')->insert()->setColumn('status', 'active');
    $rows->withKey('c')->insert()->setColumn('note', 'different columns split the batch');
});
