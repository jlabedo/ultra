<?php

declare(strict_types=1);

namespace Ultra\StateStore\Tests;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Ultra\MappedTable\Exception\PendingQueryChangesException;
use Ultra\MappedTable\GuardLockMode;
use Ultra\MappedTable\InMemoryRowDatabase;
use Ultra\MappedTable\MappedColumn;
use Ultra\MappedTable\MappedRowSetDefinition;
use Ultra\MappedTable\MappedRowSetMapping;
use Ultra\MappedTable\MappedRowSetOwner;
use Ultra\MappedTable\MappedTableUnitOfWork;
use Ultra\MappedTable\QueryGuardDefinition;
use Ultra\MappedTable\QueryGuardDependency;
use Ultra\MappedTable\Tests\Support\RecordingGuardMappedTableAdapter;
use Ultra\Mapper\UltraNormalizer;
use Ultra\Mapper_Compiler\NormalizationShapeAnalyzer;
use Ultra\StateStore\Attribute\State;
use Ultra\StateStore\Attribute\StateCollection;
use Ultra\StateStore\Attribute\StateColumn;
use Ultra\StateStore\Attribute\StateEmbedded;
use Ultra\StateStore\Attribute\StateId;
use Ultra\StateStore\StateMetadataFactory;
use Ultra\StateStore\StateMetadataRegistry;
use Ultra\StateStore\StateRowSets;
use Ultra\StateStore\StateStore;
use Ultra\UnitOfWork\Exception\ConcurrencyException;

final readonly class GuardRoleId
{
    public function __construct(
        public string $tenantId,
        public string $roleId,
    ) {}
}

final readonly class GuardRoleLabel
{
    public function __construct(
        #[StateColumn('display_name')]
        public string $name,
    ) {}
}

#[State(table: 'guard_permissions')]
final readonly class GuardPermission
{
    public function __construct(
        #[StateId(column: 'permission_name')]
        public string $name,
        public string $description = '',
    ) {}
}

#[State(table: 'guard_roles')]
final readonly class GuardRole
{
    /** @param list<GuardPermission> $permissions */
    public function __construct(
        #[StateId(columns: ['tenantId' => 'tenant_id', 'roleId' => 'role_id'])]
        public GuardRoleId $id,
        #[StateColumn('enabled')]
        public bool $active,
        public string $note,
        #[StateEmbedded(prefix: '')]
        public GuardRoleLabel $label,
        #[StateCollection(itemClass: GuardPermission::class, ownerColumns: [
            'tenantId' => 'owner_tenant',
            'roleId' => 'owner_role',
        ])]
        public array $permissions,
    ) {}
}

/** @internal */
final class QueryDependencyTest extends TestCase
{
    public static function mutations(): iterable
    {
        yield 'addition' => ['add'];
        yield 'removal' => ['remove'];
        yield 'item value change' => ['update'];
        yield 'empty collection' => ['empty'];
        yield 'root property only' => ['active'];
        yield 'root deletion' => ['delete'];
    }

    #[DataProvider('mutations')]
    public function test_mapped_dependencies_guard_state_and_collection_writes_and_reject_pending_reads(string $mutation): void
    {
        [$database, $store, $registry, $guard, $trace, $roles, $permissions] = $this->fixture();
        $id = new GuardRoleId('acme', 'editor');
        $store->observe(GuardRole::class, $id);
        $items = match ($mutation) {
            'add' => [new GuardPermission('read'), new GuardPermission('write')],
            'update' => [new GuardPermission('read', 'updated')],
            'remove', 'empty' => [],
            default => [new GuardPermission('read')],
        };
        $owner = ['owner_tenant' => 'acme', 'owner_role' => 'editor'];
        if ('delete' === $mutation) {
            $store->delete(GuardRole::class, $id);
        } elseif ('active' === $mutation) {
            $roles->withKey(['tenant_id' => 'acme', 'role_id' => 'editor'])->setColumn('enabled', false);
        } elseif ('remove' === $mutation) {
            $permissions->withKey([...$owner, 'permission_name' => 'read'])->delete();
        } else {
            $store->save(new GuardRole($id, true, '', new GuardRoleLabel('Editor'), $items));
        }
        static::assertSame(1, $guard->observe('globex', static fn(): int => 1));
        try {
            $guard->observe('acme', static fn(): int => throw new \RuntimeException('Must not read'));
            static::fail('An affected pending write must prevent the read.');
        } catch (PendingQueryChangesException $exception) {
            static::assertSame('acme', $exception->guardValue);
        }
        $registry->expectQuery(static function () use ($trace): bool {
            self::assertContains(['ultra:query_guard:iam', 'acme', GuardLockMode::EXCLUSIVE], $trace->getArrayCopy());

            return true;
        }, true);
        $database->beginTransaction();
        $registry->flush();
        $database->commit();
        $registry->clear();
        static::assertFalse($registry->hasPendingChanges());
        if ('delete' === $mutation) {
            static::assertNull($store->load(GuardRole::class, $id));
            static::assertSame([], $permissions->rowsForOwner($owner));
        } else {
            static::assertCount(count($items), $store->load(GuardRole::class, $id)->permissions);
        }
    }

    public function test_unwatched_properties_and_collection_observations_do_not_require_a_writer_guard(): void
    {
        [$database, $store, $registry, $guard, $trace, $roles] = $this->fixture();
        $id = new GuardRoleId('acme', 'editor');
        $store->observe(GuardRole::class, $id);
        // Only a root property changes. Do not enqueue collection reconciliation.
        $roles->withKey(new \Ultra\MappedTable\MappedKey(['tenant_id' => 'acme', 'role_id' => 'editor']))->setColumn(
            'note',
            'new',
        );
        static::assertSame(1, $guard->observe('acme', static fn(): int => 1));
        $database->beginTransaction();
        $registry->flush();
        $database->commit();
        static::assertSame([['ultra:query_guard:iam', 'acme', GuardLockMode::SHARED]], $trace->getArrayCopy());
    }

    public static function preparedWrites(): iterable
    {
        yield 'save' => [false];
        yield 'delete' => [true];
    }

    #[DataProvider('preparedWrites')]
    public function test_failed_replay_prevents_both_root_and_collection_writes(bool $delete): void
    {
        [$database, $store, $registry, $guard] = $this->fixture();
        $id = new GuardRoleId('acme', 'editor');
        $result = true;
        $guard->observe('acme', static function () use (&$result): bool {
            return $result;
        });
        $store->observe(GuardRole::class, $id);
        if ($delete) {
            $store->delete(GuardRole::class, $id);
        } else {
            $store->save(new GuardRole($id, false, '', new GuardRoleLabel('Changed'), []));
        }
        $result = false;
        $database->beginTransaction();
        try {
            $registry->flush();
            static::fail('Changed query must fail.');
        } catch (ConcurrencyException $exception) {
            static::assertStringContainsString('iam', $exception->getMessage());
            static::assertStringContainsString('acme', $exception->getMessage());
            $database->rollBack();
        }
        $registry->clear();
        $role = $store->load(GuardRole::class, $id);
        static::assertTrue($role->active);
        static::assertCount(1, $role->permissions);
    }

    private function fixture(): array
    {
        $database = new InMemoryRowDatabase();
        $analyzer = new NormalizationShapeAnalyzer();
        $metadataFactory = new StateMetadataFactory([
            GuardRole::class => $analyzer->forProperty(new \ReflectionProperty(GuardRole::class, 'id')),
            GuardPermission::class => $analyzer->forProperty(new \ReflectionProperty(GuardPermission::class, 'name')),
        ]);
        $state = $metadataFactory->create(GuardRole::class);
        $metadata = new StateMetadataRegistry([GuardRole::class => $state]);
        $trace = new \ArrayObject();
        $factory = new RecordingGuardMappedTableAdapter($database, static function (
            string $namespace,
            string $key,
            GuardLockMode $mode,
        ) use ($trace): void {
            $trace[] = [$namespace, $key, $mode];
        });
        $roleMapping = new GuardRolesDefinition()->mapping();
        $permissionMapping = new GuardPermissionsDefinition()->mapping();
        $roles = $factory->createDefined($roleMapping);
        $permissions = $factory->createDefined($permissionMapping);
        $registry = new MappedTableUnitOfWork(
            [GuardRolesDefinition::class => $roles, GuardPermissionsDefinition::class => $permissions],
            $factory,
            queryGuards: [new QueryGuardDefinition('iam', [
                new QueryGuardDependency(GuardRolesDefinition::class, 'tenant_id', ['enabled']),
                new QueryGuardDependency(GuardPermissionsDefinition::class, 'owner_tenant'),
            ])],
        );
        $store = new StateStore(
            $metadata,
            new StateRowSets([
                $state->tableName => $roles,
                $state->collections['permissions']->tableName => $permissions,
            ], $registry),
            new UltraNormalizer(),
        );
        $database->seedRow('guard_roles', 'role_id', [
            'tenant_id' => 'acme',
            'role_id' => 'editor',
            'enabled' => true,
            'note' => '',
            'display_name' => 'Editor',
        ]);
        $database->seedRow('guard_permissions', 'permission_name', [
            'owner_tenant' => 'acme',
            'owner_role' => 'editor',
            'permission_name' => 'read',
            'description' => '',
        ]);

        return [$database, $store, $registry, $registry->queryGuard('iam'), $trace, $roles, $permissions];
    }
}

final class GuardRolesDefinition implements MappedRowSetDefinition
{
    public function mapping(): MappedRowSetMapping
    {
        return new MappedRowSetMapping(
            'guard_roles',
            [
                'tenant_id' => new MappedColumn('string'),
                'role_id' => new MappedColumn('string'),
                'enabled' => new MappedColumn('bool'),
                'note' => new MappedColumn('string'),
                'display_name' => new MappedColumn('string'),
            ],
            ['tenant_id', 'role_id'],
        );
    }
}

final class GuardPermissionsDefinition implements MappedRowSetDefinition
{
    public function mapping(): MappedRowSetMapping
    {
        return new MappedRowSetMapping(
            'guard_permissions',
            [
                'owner_tenant' => new MappedColumn('string'),
                'owner_role' => new MappedColumn('string'),
                'permission_name' => new MappedColumn('string'),
                'description' => new MappedColumn('string'),
            ],
            ['owner_tenant', 'owner_role', 'permission_name'],
            owner: new MappedRowSetOwner(GuardRolesDefinition::class, [
                'owner_tenant' => 'tenant_id',
                'owner_role' => 'role_id',
            ]),
        );
    }
}
