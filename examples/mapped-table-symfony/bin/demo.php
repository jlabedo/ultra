#!/usr/bin/env php
<?php

declare(strict_types=1);

use MappedTableSymfonyExample\CapacityExceeded;
use MappedTableSymfonyExample\DemoDatabase;
use MappedTableSymfonyExample\WorkshopContainer;
use MappedTableSymfonyExample\WorkshopRegistration;
use Ultra\UnitOfWork\Exception\ConcurrencyException;
use Ultra\UnitOfWork\UnitOfWorkRunner;

require dirname(__DIR__) . '/bootstrap.php';

$dsn = getenv('MAPPED_TABLE_DATABASE_DSN');
if (false === $dsn || '' === $dsn) {
    throw new RuntimeException('Set MAPPED_TABLE_DATABASE_DSN to a PostgreSQL connection URL.');
}
$compiled = WorkshopContainer::compile();
$database = DemoDatabase::open($dsn);
$deskConnection = null;

try {
    $deskConnection = $database->newSession();
    $webContainer = $compiled->using($database->connection);
    $web = $webContainer->get(WorkshopRegistration::class);
    $desk = $compiled->using($deskConnection)->get(WorkshopRegistration::class);
    $webRunner = $webContainer->get(UnitOfWorkRunner::class);

    $workshop = 'intro-woodwork';
    $web->publish($workshop, 'Initiation au travail du bois', 6, ['club-local' => 2]);
    $web->reserve($workshop, 'groupe-amis', 3);
    printf("Atelier publié, %d places réservées sur 6.\n", $web->reservedSeats($workshop));

    try {
        $webRunner->run(static function () use ($web, $desk, $workshop): void {
            $web->reserve($workshop, 'inscription-web', 1);
            $desk->reserve($workshop, 'inscription-guichet', 1);
        });
        throw new LogicException('Expected a conflict on the last seat.');
    } catch (ConcurrencyException) {
        echo "Dernière place confirmée au guichet. Le rejeu détecte le conflit pour l’inscription web.\n";
    }

    try {
        $web->reserve($workshop, 'inscription-web', 1);
        throw new LogicException('Expected the workshop to be full.');
    } catch (CapacityExceeded) {
        printf(
            "Nouvelle tentative web refusée. L’atelier affiche %d places réservées sur 6.\n",
            $web->reservedSeats($workshop),
        );
    }

    $web->withdraw($workshop);
    printf("Atelier retiré. Total des places encore réservées = %d.\n", $web->reservedSeats($workshop));
} finally {
    $deskConnection?->close();
    $database->close();
}
