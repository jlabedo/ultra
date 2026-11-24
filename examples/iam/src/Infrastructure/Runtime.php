<?php

declare(strict_types=1);

namespace IamExample\Infrastructure;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\TransactionIsolationLevel;
use IamExample\Administration;
use IamExample\Authorization;
use IamExample\Infrastructure\Rows\IamSchema;
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
use Ultra\MappedTable\MappedTableUnitOfWork;
use Ultra\MappedTable\PostgresMappedTableAdapter;
use Ultra\MappedTable\QueryGuard;
use Ultra\MappedTable\QueryGuardDefinition;
use Ultra\MappedTable\QueryGuardDependency;
use Ultra\Mapper\UltraNormalizer;
use Ultra\Mapper_Compiler\NormalizationShapeAnalyzer;
use Ultra\StateStore\Attribute\StateId;
use Ultra\StateStore\StateMetadataFactory;
use Ultra\StateStore\StateMetadataRegistry;
use Ultra\StateStore\StateRowSets;
use Ultra\StateStore\StateStore;
use Ultra\UnitOfWork\DbalTransactionManager;
use Ultra\UnitOfWork\TransactionalUnitOfWorkRunner;
use Ultra\UnitOfWork\TransactionManager;

/** Composes StateStore views and MappedTable row sets on one connection. */
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
        $adapter = new PostgresMappedTableAdapter($connection);
        $metadata = [];
        foreach ($classes as $class) {
            $metadata[$class] = $metadataFactory->create($class)->toArray();
        }

        $rowSets = [];
        $tables = [];
        $schema = IamSchema::definition();
        $schemaTables = $schema->tablesById();
        foreach (IamSchema::rowSets() as $selection) {
            $row = $selection->compileFor($adapter, $schema)();
            $rowSets[$selection->table] = $row;
            $tables[$schemaTables[$selection->table]->name] = $row;
        }
        $unitOfWork = new MappedTableUnitOfWork(
            $rowSets,
            $adapter,
            queryGuards: [new QueryGuardDefinition('iam_authorization', [
                new QueryGuardDependency(IamSchema::SUBJECTS, 'tenant_id', ['active']),
                new QueryGuardDependency(IamSchema::GROUPS, 'tenant_id', ['active']),
                new QueryGuardDependency(IamSchema::MEMBERSHIPS, 'tenant_id', ['active']),
                new QueryGuardDependency(IamSchema::BINDINGS, 'tenant_id', ['active', 'expires_at']),
                new QueryGuardDependency(IamSchema::ROLES, 'tenant_id', ['active']),
                new QueryGuardDependency(IamSchema::ROLE_PERMISSIONS, 'tenant_id'),
            ])],
        );
        $this->states = new StateStore(
            new StateMetadataRegistry($metadata),
            new StateRowSets($tables, $unitOfWork),
            new UltraNormalizer(__DIR__ . '/../../var/normalizers'),
        );
        $this->authorizationGuard = $unitOfWork->queryGuard('iam_authorization');
        $this->runner = new TransactionalUnitOfWorkRunner(
            $unitOfWork,
            $transactions ?? new DbalTransactionManager($connection, TransactionIsolationLevel::READ_COMMITTED),
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
