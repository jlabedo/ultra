#!/usr/bin/env php
<?php

declare(strict_types=1);

use ComplianceExample\Requirements;
use ComplianceExample\Runtime;
use ComplianceExample\Schema;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Tools\DsnParser;

require dirname(__DIR__) . '/bootstrap.php';

$dsn = getenv('ULTRA_TEST_POSTGRES_DSN') ?: 'pdo-pgsql://ultra:ultra@127.0.0.1:55432/ultra_test';
$connection = DriverManager::getConnection(new DsnParser(['postgres' => 'pdo_pgsql'])->parse($dsn));
$schema = 'compliance_demo_' . bin2hex(random_bytes(8));
$connection->executeStatement('CREATE SCHEMA ' . $schema);
try {
    $connection->executeStatement('SET search_path TO ' . $schema);
    $connection->transactional(Schema::install(...));
    $runtime = new Runtime($connection, subjectBatchSize: 1);
    $compliance = $runtime->compliance;
    $compliance->define('acme', 'base', new Requirements([['id', 'passport'], ['insurance']]));
    $compliance->provide('acme', 'ada', 'passport', 'passport');
    $compliance->provide('acme', 'ada', 'insurance', 'insurance');
    $compliance->provide('acme', 'lin', 'id', 'id');
    $runtime->project();
    $runtime->refresh('acme');
    printf("Sujets habilités (base) : %s\n", implode(', ', $runtime->queries->subjects('acme', 'base')));

    $compliance->define('acme', 'base_v2', new Requirements([['id', 'passport'], ['insurance'], ['rge']]));
    $runtime->project();
    $runtime->refresh('acme', maxSteps: 1);
    $duringBuild = $runtime->queries->qualifications('acme', 'ada');
    printf("Habilitations d’Ada pendant la construction : %s\n", implode(', ', $duringBuild));
    $runtime->refresh('acme');
    $compliance->provide('acme', 'ada', 'rge', 'rge');
    $runtime->project();
    $runtime->refresh('acme');
    printf("Après recalcul d’Ada : %s\n", implode(', ', $runtime->queries->qualifications('acme', 'ada')));

    $compliance->withdraw('acme', 'ada', 'insurance');
    $runtime->project();
    printf("Avant rafraîchissement : %s\n", implode(', ', $runtime->queries->qualifications('acme', 'ada')));
    $runtime->refresh('acme');
    printf("Après retrait : %d habilitation(s)\n", count($runtime->queries->qualifications('acme', 'ada')));
} finally {
    $connection->executeStatement('RESET search_path');
    $connection->executeStatement('DROP SCHEMA ' . $schema . ' CASCADE');
    $connection->close();
}
