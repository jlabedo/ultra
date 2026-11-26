<?php

declare(strict_types=1);

use Composer\InstalledVersions;
use Ultra\MappedTable\InMemoryMappedTableAdapter;
use Ultra\MappedTable\InMemoryRowDatabase;
use Ultra\MappedTable\MappedRowSetDefinition;
use Ultra\MappedTable\MappedTableUnitOfWork;

require __DIR__ . '/vendor/autoload.php';

if (InstalledVersions::isInstalled('ultra/schema') || InstalledVersions::isInstalled('ultra/projection')) {
    throw new LogicException('Standalone MappedTable must install without Schema or Projection.');
}

$database = new InMemoryRowDatabase();
$adapter = new InMemoryMappedTableAdapter($database);
$definition = new MappedRowSetDefinition('documents', ['id', 'title'], ['id']);
$rows = $adapter->compileRowSet($definition)();
$writer = new MappedTableUnitOfWork(['documents' => $rows], $adapter);
$rows->withKey(1)->insert()->setColumn('title', 'Workshop');
$database->transactional($writer->flush(...));

if ($rows->hasPendingChanges() || ['title' => 'Workshop'] !== $rows->withKey(1)->get(['title'])) {
    throw new LogicException('Expected the native row-set definition to persist the document.');
}

echo "Consumer smoke passed.\n";
