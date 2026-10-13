<?php

declare(strict_types=1);

require __DIR__ . '/vendor/autoload.php';

final readonly class ConsumerRecord
{
    public function __construct(
        public string $id,
        public int $count,
    ) {}
}

function verify(bool $condition, string $label): void
{
    if (!$condition) {
        throw new RuntimeException($label);
    }
}

$normalizer = new Ultra\Mapper\UltraNormalizer(__DIR__ . '/normalizer-cache', 'ConsumerGenerated');
$record = new ConsumerRecord('sample', 3);
$scalar = $normalizer->toScalar($record);
verify(['id' => 'sample', 'count' => 3] === $scalar, 'Compiled normalization failed.');
verify($normalizer->fromScalar($scalar, ConsumerRecord::class) == $record, 'Compiled denormalization failed.');

$bundle = new Ultra\SymfonyBundle\UltraBundle();
$container = new Symfony\Component\DependencyInjection\ContainerBuilder();
$container->setParameter('kernel.build_dir', __DIR__ . '/container-build');
$bundle->build($container);
verify($container->hasDefinition(Ultra\Mapper\UltraNormalizer::class), 'Mapper service missing.');
verify($container->hasDefinition(Ultra\StateStore\StateStore::class), 'StateStore service missing.');
verify(class_exists(Ultra\Projection\Bundle\Command\ProjectionWorkerCommand::class), 'Projection command missing.');
verify(class_exists(Ultra\EventStore_Postgres\PostgresEventStoreHStore::class), 'PostgreSQL event store missing.');
verify(class_exists(Ultra\StateStore\StateStore::class), 'StateStore missing.');

$tracker = new Ultra\Coordination\InMemory\InMemoryDirtyKeyTracker();
$tracker->bump('consumer', 'sample');
verify(1 === $tracker->pending()[0]->counter, 'Coordination failed.');
verify(class_exists(Doctrine\DBAL\DriverManager::class), 'DBAL missing.');
verify(!Composer\InstalledVersions::isInstalled('phpunit/phpunit'), 'Development tools were installed.');

echo "Consumer smoke passed.\n";
