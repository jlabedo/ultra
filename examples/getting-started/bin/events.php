<?php

declare(strict_types=1);

require dirname(__DIR__) . '/vendor/autoload.php';

use GettingStarted\Database;
use Ultra\EventStore\DomainIdentifier;
use Ultra\EventStore\Event;
use Ultra\EventStore\StreamQuery;
use Ultra\EventStore_Postgres\PostgresEventStoreQueryStreams;

$store = new PostgresEventStoreQueryStreams(Database::connect(), 'tutorial_es_');
$courseId = 'course-' . bin2hex(random_bytes(4));
$query = StreamQuery::forStream(new DomainIdentifier('courseId', $courseId))->ofTypes('tutorial.course.named');
$before = $store->load($query);
$store->append([
    new Event('tutorial.course.named', ['courseId' => $courseId, 'name' => 'Ultra basics'], ['courseId' => $courseId]),
], $before->appendCondition());

$page = $store->load($query, batchSize: 1);
$count = count($page->events);
while ($page->hasMore) {
    $page = $store->load($query, from: $page->checkpoint, batchSize: 1);
    $count += count($page->events);
}
if (1 !== $count) {
    throw new RuntimeException('Expected one event in the new course stream.');
}
echo "Stored and loaded 1 event.\n";
