<?php

declare(strict_types=1);

namespace Ultra\StateStore\Tests;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Ultra\MappedTable\InMemoryMappedRowSetAdapterFactory;
use Ultra\MappedTable\InMemoryRowDatabase;
use Ultra\MappedTable\MappedRowSet;
use Ultra\Mapper\UltraNormalizer;
use Ultra\Mapper_Compiler\NormalizationShapeAnalyzer;
use Ultra\StateStore\Attribute\State;
use Ultra\StateStore\Attribute\StateCollection;
use Ultra\StateStore\Attribute\StateColumn;
use Ultra\StateStore\Attribute\StateEmbedded;
use Ultra\StateStore\Attribute\StateId;
use Ultra\StateStore\Exception\PendingQueryChangesException;
use Ultra\StateStore\GuardLockAdapter;
use Ultra\StateStore\GuardLockMode;
use Ultra\StateStore\QueryGuard;
use Ultra\StateStore\QueryGuardDefinition;
use Ultra\StateStore\StateMetadataFactory;
use Ultra\StateStore\StateMetadataRegistry;
use Ultra\StateStore\StateStore;
use Ultra\StateStore\StateTableRegistry;
use Ultra\UnitOfWork\Exception\ConcurrencyException;

final readonly class GuardRoleId
{
    public function __construct(public string $tenantId, public string $roleId) {}
}

final readonly class GuardRoleLabel
{
    public function __construct(#[StateColumn('display_name')] public string $name) {}
}

#[State(table: 'guard_permissions')]
final readonly class GuardPermission
{
    public function __construct(#[StateId(column: 'permission_name')] public string $name, public string $description = '') {}
}

#[State(table: 'guard_roles')]
final readonly class GuardRole
{
    /** @param list<GuardPermission> $permissions */
    public function __construct(
        #[StateId(columns: ['tenantId' => 'tenant_id', 'roleId' => 'role_id'])] public GuardRoleId $id,
        #[StateColumn('enabled')] public bool $active,
        public string $note,
        #[StateEmbedded(prefix: '')] public GuardRoleLabel $label,
        #[StateCollection(itemClass: GuardPermission::class, ownerColumns: ['tenantId' => 'owner_tenant', 'roleId' => 'owner_role'])] public array $permissions,
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
    public function test_object_declarations_guard_property_or_collection_writes_and_reject_pending_reads(string $mutation): void
    {
        [$database, $store, $registry, $guard, $trace, $metadata] = $this->fixture();
        $id = new GuardRoleId('acme', 'editor');
        $store->observe(GuardRole::class, $id);
        $items = match ($mutation) {
            'add' => [new GuardPermission('read'), new GuardPermission('write')],
            'update' => [new GuardPermission('read', 'updated')],
            'remove', 'empty' => [],
            default => [new GuardPermission('read')],
        };
        $state = $metadata->metadataFor(GuardRole::class);
        if ('delete' === $mutation) {
            $store->delete(GuardRole::class, $id);
        } elseif ('active' === $mutation) {
            $registry->tableFor($state)->withKey($state->keyFromNormalizedId(['tenantId' => 'acme', 'roleId' => 'editor']))->setColumn('enabled', false);
        } elseif ('remove' === $mutation) {
            $collection = $state->collections['permissions'];
            $owner = $collection->ownerKey(['tenantId' => 'acme', 'roleId' => 'editor']);
            $registry->collectionFor($collection, ['tenantId' => 'acme', 'roleId' => 'editor'])->withKey($collection->itemKey(['name' => 'read'], $owner))->delete();
        } else {
            $store->save(new GuardRole($id, true, '', new GuardRoleLabel('Editor'), $items));
        }
        self::assertSame(1, $guard->observe('globex', static fn (): int => 1));
        try {
            $guard->observe('acme', static fn (): int => throw new \RuntimeException('Must not read'));
            self::fail('An affected pending write must prevent the read.');
        } catch (PendingQueryChangesException $exception) {
            self::assertSame('acme', $exception->guardValue);
        }
        $registry->expectQuery(static function () use ($trace): bool {
            self::assertContains(['ultra:query_guard:iam', 'acme', GuardLockMode::EXCLUSIVE], $trace->getArrayCopy());

            return true;
        }, true);
        $database->beginTransaction();
        $registry->flush();
        $database->commit();
        $registry->clear();
        self::assertFalse($registry->hasPendingChanges());
        if ('delete' === $mutation) {
            self::assertNull($store->load(GuardRole::class, $id));
            self::assertSame([], $registry->collectionFor($state->collections['permissions'], ['tenantId' => 'acme', 'roleId' => 'editor'])->rows());
        } else {
            self::assertCount(count($items), $store->load(GuardRole::class, $id)->permissions);
        }
    }

    public function test_unwatched_properties_and_collection_observations_do_not_require_a_writer_guard(): void
    {
        [$database, $store, $registry, $guard, $trace, $metadata] = $this->fixture();
        $id = new GuardRoleId('acme', 'editor');
        $store->observe(GuardRole::class, $id);
        // Only a root property changes. Do not enqueue collection reconciliation.
        $registry->tableFor($metadata->metadataFor(GuardRole::class))->withKey(new \Ultra\MappedTable\MappedKey(['tenant_id' => 'acme', 'role_id' => 'editor']))->setColumn('note', 'new');
        self::assertSame(1, $guard->observe('acme', static fn (): int => 1));
        $database->beginTransaction();
        $registry->flush();
        $database->commit();
        self::assertSame([['ultra:query_guard:iam', 'acme', GuardLockMode::SHARED]], $trace->getArrayCopy());
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
        $guard->observe('acme', static function () use (&$result): bool { return $result; });
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
            self::fail('Changed query must fail.');
        } catch (ConcurrencyException $exception) {
            self::assertStringContainsString('iam', $exception->getMessage());
            self::assertStringContainsString('acme', $exception->getMessage());
            $database->rollBack();
        }
        $registry->clear();
        $role = $store->load(GuardRole::class, $id);
        self::assertTrue($role->active);
        self::assertCount(1, $role->permissions);
    }

    public function test_property_paths_and_owner_column_renames_compile_to_physical_dependencies(): void
    {
        [, , , , , $metadata] = $this->fixture();
        $definition = new QueryGuardDefinition();
        $definition->watch(GuardRole::class, scopeBy: 'id.tenantId')
            ->whenPropertiesChange('label.name')->whenPropertiesChange('active')
            ->whenPropertiesChange('permissions');
        $rules = $definition->compile($metadata);
        self::assertContains(['tableKey' => $metadata->metadataFor(GuardRole::class)->tableKey, 'keyBy' => 'tenant_id', 'columns' => ['display_name', 'enabled']], $rules);
        self::assertContains(['tableKey' => $metadata->metadataFor(GuardRole::class)->collections['permissions']->tableKey, 'keyBy' => 'owner_tenant', 'columns' => null], $rules);
        $all = new QueryGuardDefinition();
        $all->watch(GuardRole::class, scopeBy: 'id.tenantId');
        self::assertCount(2, $all->compile($metadata));
        $onlyProperty = new QueryGuardDefinition();
        $onlyProperty->watch(GuardRole::class, scopeBy: 'id.tenantId')->whenPropertiesChange('active');
        self::assertCount(1, $onlyProperty->compile($metadata));
        $onlyCollection = new QueryGuardDefinition();
        $onlyCollection->watch(GuardRole::class, scopeBy: 'id.tenantId')->whenPropertiesChange('permissions');
        self::assertContains(['tableKey' => $metadata->metadataFor(GuardRole::class)->tableKey, 'keyBy' => 'tenant_id', 'columns' => []], $onlyCollection->compile($metadata));
    }

    public function test_scalar_identifier_scopes_use_the_collection_owner_mapping(): void
    {
        $collection = new \Ultra\StateStore\StateCollectionMetadata('items', 'items', 'items', true,
            ['id' => 'owner_id'], \stdClass::class, 'id', true, ['id' => 'item_id'], []);
        $state = new \Ultra\StateStore\StateMetadata(\stdClass::class, 'owners', 'owners', 'id', 'string', true,
            ['id' => 'owner_key'], [], [], collections: ['items' => $collection]);
        $definition = new QueryGuardDefinition();
        $definition->watch(\stdClass::class, scopeBy: 'id')->whenPropertiesChange('items');
        self::assertSame([
            ['tableKey' => 'items', 'keyBy' => 'owner_id', 'columns' => null],
            ['tableKey' => 'owners', 'keyBy' => 'owner_key', 'columns' => []],
        ], $definition->compile(new StateMetadataRegistry([\stdClass::class => $state])));
    }

    public function test_unregistered_states_cannot_be_watched(): void
    {
        $definition = new QueryGuardDefinition();
        $definition->watch(GuardRole::class, scopeBy: 'id.tenantId');
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('unregistered state');
        $definition->compile(new StateMetadataRegistry([]));
    }

    public static function invalidDeclarations(): iterable
    {
        yield 'physical column instead of property' => ['id.tenantId', 'enabled', \LogicException::class];
        yield 'unknown path' => ['id.missing', 'active', \LogicException::class];
        yield 'unknown collection' => ['id.tenantId', 'missing', \LogicException::class];
        yield 'collection cannot scope by root value' => ['note', 'permissions', \LogicException::class];
        yield 'empty properties' => ['id.tenantId', '', \InvalidArgumentException::class];
        yield 'item property paths are not supported' => ['id.tenantId', 'permissions.active', \LogicException::class];
    }

    #[DataProvider('invalidDeclarations')]
    public function test_invalid_object_dependencies_are_rejected(string $scope, string $property, string $exception): void
    {
        [, , , , , $metadata] = $this->fixture();
        $this->expectException($exception);
        $definition = new QueryGuardDefinition();
        $watch = $definition->watch(GuardRole::class, scopeBy: $scope);
        $watch->whenPropertiesChange(...('' === $property ? [] : [$property]));
        $definition->compile($metadata);
    }

    private function fixture(): array
    {
        $database = new InMemoryRowDatabase();
        $factory = new InMemoryMappedRowSetAdapterFactory($database);
        $analyzer = new NormalizationShapeAnalyzer();
        $metadataFactory = new StateMetadataFactory([
            GuardRole::class => $analyzer->forProperty(new \ReflectionProperty(GuardRole::class, 'id')),
            GuardPermission::class => $analyzer->forProperty(new \ReflectionProperty(GuardPermission::class, 'name')),
        ]);
        $state = $metadataFactory->create(GuardRole::class);
        $metadata = new StateMetadataRegistry([GuardRole::class => $state]);
        $definition = new QueryGuardDefinition();
        $definition->watch(GuardRole::class, scopeBy: 'id.tenantId')->whenPropertiesChange('active', 'permissions');
        $trace = new \ArrayObject();
        $adapter = new class($trace) implements GuardLockAdapter {
            public function __construct(private \ArrayObject $trace) {}
            public function acquire(string $namespace, string $key, GuardLockMode $mode): void { $this->trace[] = [$namespace, $key, $mode]; }
        };
        $registry = new StateTableRegistry([$state->tableKey => new MappedRowSet($factory->create($state->tableName, $state->keyColumns()))], $factory, ['iam' => $definition->compile($metadata)], $adapter);
        $store = new StateStore($metadata, $registry, new UltraNormalizer());
        $database->seedRow('guard_roles', 'role_id', ['tenant_id' => 'acme', 'role_id' => 'editor', 'enabled' => true, 'note' => '', 'display_name' => 'Editor']);
        $database->seedRow('guard_permissions', 'permission_name', ['owner_tenant' => 'acme', 'owner_role' => 'editor', 'permission_name' => 'read', 'description' => '']);

        return [$database, $store, $registry, new QueryGuard($registry, 'iam'), $trace, $metadata];
    }
}
