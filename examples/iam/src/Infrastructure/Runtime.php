<?php

declare(strict_types=1);

namespace IamExample\Infrastructure;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\TransactionIsolationLevel;
use IamExample\Administration;
use IamExample\Authorization;
use IamExample\Provisioning;
use IamExample\State\AuditEntry;
use IamExample\State\BindingState;
use IamExample\State\GroupState;
use IamExample\State\MembershipState;
use IamExample\State\PermissionState;
use IamExample\State\RoleState;
use IamExample\State\SubjectState;
use IamExample\State\TenantState;
use Psr\Clock\ClockInterface;
use Ultra\MappedTable\MappedRowSet;
use Ultra\MappedTable\PostgresMappedRowSetAdapterFactory;
use Ultra\Mapper\UltraNormalizer;
use Ultra\Mapper_Compiler\NormalizationShapeAnalyzer;
use Ultra\StateStore\Attribute\StateId;
use Ultra\StateStore\PostgresGuardLockAdapter;
use Ultra\StateStore\QueryGuard;
use Ultra\StateStore\QueryGuardDefinition;
use Ultra\StateStore\StateMetadataFactory;
use Ultra\StateStore\StateMetadataRegistry;
use Ultra\StateStore\StateStore;
use Ultra\StateStore\StateTableRegistry;
use Ultra\UnitOfWork\DoctrineTransactionManager;
use Ultra\UnitOfWork\TransactionalUnitOfWorkRunner;
use Ultra\UnitOfWork\TransactionManager;

/** Explicit composition root; no Symfony container or hand-written mapping metadata. */
final readonly class Runtime
{
    public Administration $administration;
    public Authorization $authorization;
    public QueryGuard $authorizationGuard;
    public Provisioning $provisioning;
    public StateStore $states;
    public TransactionalUnitOfWorkRunner $runner;

    public function __construct(Connection $connection, ClockInterface $clock, ?TransactionManager $transactions = null)
    {
        $classes = [
            TenantState::class,
            SubjectState::class,
            GroupState::class,
            RoleState::class,
            BindingState::class,
            MembershipState::class,
            AuditEntry::class,
        ];
        $shapes = [];
        $analyzer = new NormalizationShapeAnalyzer();
        foreach ([...$classes, PermissionState::class] as $class) {
            foreach (new \ReflectionClass($class)->getProperties() as $property) {
                if ([] === $property->getAttributes(StateId::class)) {
                    continue;
                }

                $shapes[$class] = $analyzer->forProperty($property);
            }
        }
        $metadataFactory = new StateMetadataFactory($shapes);
        $adapters = new PostgresMappedRowSetAdapterFactory($connection);
        $metadata = [];
        $tables = [];
        foreach ($classes as $class) {
            $state = $metadataFactory->create($class);
            $metadata[$class] = $state->toArray();
            $tables[$state->tableKey] = new MappedRowSet($adapters->create(
                $state->tableName,
                array_values($state->idPropertyColumns),
            ));
        }
        $metadataRegistry = new StateMetadataRegistry($metadata);
        $definition = new QueryGuardDefinition();
        foreach ([SubjectState::class, GroupState::class, MembershipState::class] as $class) {
            $definition->watch($class, scopeBy: 'id.tenantId')->whenPropertiesChange('active');
        }
        $definition->watch(BindingState::class, scopeBy: 'id.tenantId')->whenPropertiesChange('active', 'expiresAt');
        $definition->watch(RoleState::class, scopeBy: 'id.tenantId')->whenPropertiesChange('active', 'permissions');
        $registry = new StateTableRegistry(
            $tables,
            $adapters,
            queryGuardDefinitions: ['iam_authorization' => $definition->compile($metadataRegistry)],
            guardLockAdapter: new PostgresGuardLockAdapter($connection),
        );
        $this->states = new StateStore(
            $metadataRegistry,
            $registry,
            new UltraNormalizer(__DIR__ . '/../../var/normalizers'),
        );
        $this->authorizationGuard = new QueryGuard($registry, 'iam_authorization');
        $this->runner = new TransactionalUnitOfWorkRunner(
            $registry,
            $transactions ?? new DoctrineTransactionManager($connection, TransactionIsolationLevel::READ_COMMITTED),
        );
        $this->authorization = new Authorization($connection, $clock);
        $this->administration = new Administration(
            $this->states,
            $this->runner,
            $this->authorization,
            $this->authorizationGuard,
            $clock,
        );
        $this->provisioning = new Provisioning($this->states, $this->runner, $clock);
    }
}
