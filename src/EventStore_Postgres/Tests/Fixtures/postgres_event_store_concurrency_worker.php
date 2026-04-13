<?php

declare(strict_types=1);

use Ultra\EventStore\Event;
use Ultra\EventStore\Exception\ConcurrencyException;
use Ultra\EventStore\QueryVersion;
use Ultra\EventStore\StreamQuery;

use function Ultra\EventStore_Postgres\Tests\Support\postgresTestConnection;
use function Ultra\EventStore_Postgres\Tests\Support\postgresTestStoreForImplementation;

require_once __DIR__.'/../../../../vendor/autoload.php';
require_once __DIR__.'/../Support/PostgresTestSupport.php';

/**
 * @param array<string, mixed> $message
 */
function emitMessage(array $message): void
{
    fwrite(STDOUT, json_encode($message, JSON_THROW_ON_ERROR).PHP_EOL);
    fflush(STDOUT);
}

$configEncoded = getenv('ULTRA_WORKER_CONFIG');
if (false === $configEncoded || '' === $configEncoded) {
    fwrite(STDERR, "Missing ULTRA_WORKER_CONFIG\n");

    exit(64);
}

$decoded = base64_decode($configEncoded, true);
if (false === $decoded) {
    fwrite(STDERR, "Invalid ULTRA_WORKER_CONFIG payload\n");

    exit(64);
}

$config = json_decode($decoded, true, 512, JSON_THROW_ON_ERROR);
$connection = postgresTestConnection($config['dsn']);
$store = postgresTestStoreForImplementation(
    $connection,
    $config['tablePrefix'],
    $config['implementation'] ?? 'hstore',
    lockStrategy: $config['lockStrategy'] ?? 'shared-exclusive',
);
$expectedVersion = null;
if (isset($config['version'], $config['query'])) {
    $query = new StreamQuery(
        domainIdentifiers: $config['query']['domainIdentifiers'],
        eventNames: $config['query']['eventNames'],
        orCriteria: $config['query']['orCriteria'],
        orBranchEventNames: $config['query']['orBranchEventNames'],
    );
    $expectedVersion = new QueryVersion((int) $config['version'], $query);
}
$event = new Event(
    eventType: $config['event']['eventType'],
    payload: $config['event']['payload'],
    domainIds: $config['event']['domainIds'],
);
$expectedVersions = null === $expectedVersion ? [] : [$expectedVersion];

try {
    if (isset($config['lockTimeoutMs'])) {
        $connection->executeStatement(sprintf("SET lock_timeout TO '%dms'", $config['lockTimeoutMs']));
    }

    if ('hold_then_commit' === $config['mode']) {
        $connection->beginTransaction();
        $store->append([$event], ...$expectedVersions);
        emitMessage([
            'type' => 'event',
            'name' => 'ready',
        ]);

        $releaseSignal = fgets(STDIN);
        if (false === $releaseSignal || 'release' !== trim($releaseSignal)) {
            throw new RuntimeException('Expected "release" on STDIN.');
        }

        $connection->commit();
        emitMessage([
            'type' => 'result',
            'status' => 'ok',
        ]);

        exit(0);
    }

    emitMessage([
        'type' => 'event',
        'name' => 'attempting',
    ]);

    $store->append([$event], ...$expectedVersions);
    emitMessage([
        'type' => 'result',
        'status' => 'ok',
    ]);

    exit(0);
} catch (ConcurrencyException $exception) {
    if ($connection->isTransactionActive()) {
        $connection->rollBack();
    }

    emitMessage([
        'type' => 'result',
        'status' => 'concurrency_exception',
        'message' => $exception->getMessage(),
    ]);

    exit(10);
} catch (Throwable $exception) {
    if ($connection->isTransactionActive()) {
        $connection->rollBack();
    }

    emitMessage([
        'type' => 'result',
        'status' => 'error',
        'class' => $exception::class,
        'message' => $exception->getMessage(),
    ]);

    fwrite(STDERR, $exception::class.': '.$exception->getMessage().PHP_EOL);

    exit(1);
}
