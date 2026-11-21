<?php

declare(strict_types=1);

namespace ComplianceExample;

use ComplianceExample\Event\EvidenceProvided;
use ComplianceExample\Event\EvidenceWithdrawn;
use ComplianceExample\Event\QualificationDefined;
use ComplianceExample\Refresh\EligibilityRefresh;
use ComplianceExample\State\EvidenceState;
use ComplianceExample\State\QualificationState;
use ComplianceExample\Write\DefineQualification;
use ComplianceExample\Write\ProvideEvidence;
use ComplianceExample\Write\WithdrawEvidence;
use Doctrine\DBAL\Connection;
use Symfony\Component\Config\Loader\LoaderInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\HttpKernel\Kernel as BaseKernel;
use Ultra\Coordination\Refresh\RefreshPublication;
use Ultra\Coordination\Refresh\RefreshWorker;
use Ultra\Coordination\Tracking\DirtyKeyTracker;
use Ultra\Coordination_Postgres\Refresh\PostgresRefreshPublication;
use Ultra\Coordination_Postgres\Tracking\PostgresDirtyKeyTracker;
use Ultra\EventStore\EventStore;
use Ultra\EventStore_Postgres\PostgresEventStoreQueryStreams;
use Ultra\MappedTable\MappedTableAdapter;
use Ultra\MappedTable\PostgresMappedTableAdapter;
use Ultra\Projection\Bundle\ProjectionImplementationInterface;
use Ultra\Projection\Coordination\ProjectionActivatorInterface;
use Ultra\Projection\Coordination\ProjectionCoordinator;
use Ultra\Projection\InMemory\InMemoryProjectionActivator;
use Ultra\Projection_Postgres\PostgresProjectionImplementation;
use Ultra\SymfonyBundle\UltraBundle;
use Ultra\UnitOfWork\DbalTransactionManager;
use Ultra\UnitOfWork\TransactionalUnitOfWorkRunner;
use Ultra\UnitOfWork\TransactionManager;
use Ultra\UnitOfWork\UnitOfWorkRunner;

final class Kernel extends BaseKernel
{
    public function getProjectDir(): string
    {
        return dirname(__DIR__);
    }

    public function getCacheDir(): string
    {
        // Generated services include absolute dependency paths. Isolate the two Composer installations.
        $kernelFile = new \ReflectionClass(BaseKernel::class)->getFileName();

        return parent::getCacheDir() . '/' . substr(hash('sha256', (string) $kernelFile), 0, 12);
    }

    public function registerBundles(): iterable
    {
        yield new UltraBundle();
    }

    public function registerContainerConfiguration(LoaderInterface $loader): void
    {
        $loader->load(static function (ContainerBuilder $container): void {
            $container->register(Connection::class)->setSynthetic(true)->setPublic(true);

            foreach ([
                EventStore::class => PostgresEventStoreQueryStreams::class,
                TransactionManager::class => DbalTransactionManager::class,
                ProjectionImplementationInterface::class => PostgresProjectionImplementation::class,
                ProjectionActivatorInterface::class => InMemoryProjectionActivator::class,
                MappedTableAdapter::class => PostgresMappedTableAdapter::class,
            ] as $id => $class) {
                $container->register($id, $class)->setAutowired(true);
            }
            $container
                ->register(DirtyKeyTracker::class, PostgresDirtyKeyTracker::class)
                ->setAutowired(true)
                ->setArgument('$table', Schema::DIRTY_KEYS);
            $container
                ->register(RefreshPublication::class, PostgresRefreshPublication::class)
                ->setAutowired(true)
                ->setArgument('$table', Schema::REFRESH_REVISIONS);

            foreach ([
                DefineQualification::class,
                ProvideEvidence::class,
                WithdrawEvidence::class,
                EvidenceProvided::class,
                EvidenceWithdrawn::class,
                QualificationDefined::class,
                EvidenceState::class,
                QualificationState::class,
                SourceProjection::class,
            ] as $class) {
                $container
                    ->register($class)
                    ->setAutoconfigured(true)
                    ->setAutowired(SourceProjection::class === $class);
            }
            $container->register(RefreshWorker::class)->setAutowired(true);
            foreach ([
                Compliance::class,
                EligibilityQueries::class,
                ReferenceQueries::class,
                EligibilityRefresh::class,
            ] as $class) {
                $container->register($class)->setAutowired(true)->setPublic(true);
            }
            $container->getDefinition(ProjectionCoordinator::class)->setPublic(true);
            $container->setAlias(UnitOfWorkRunner::class, TransactionalUnitOfWorkRunner::class)->setPublic(true);
        });
    }
}
