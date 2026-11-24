<?php

declare(strict_types=1);

namespace IamExample\Infrastructure\Rows;

use Ultra\MappedTable\SchemaMappedRowSet;
use Ultra\Schema\ColumnDefinition;
use Ultra\Schema\ColumnType;
use Ultra\Schema\ForeignKeyDefinition;
use Ultra\Schema\SchemaDefinition;
use Ultra\Schema\TableDefinition;

final class IamSchema
{
    public const string AUDIT = 'iam_audit';
    public const string BINDINGS = 'iam_bindings';
    public const string GROUPS = 'iam_groups';
    public const string MEMBERSHIPS = 'iam_memberships';
    public const string ROLE_PERMISSIONS = 'iam_role_permissions';
    public const string ROLES = 'iam_roles';
    public const string SUBJECTS = 'iam_subjects';
    public const string TENANTS = 'iam_tenants';

    public static function definition(): SchemaDefinition
    {
        return new SchemaDefinition([
            new TableDefinition(
                self::AUDIT,
                columns: [
                    new ColumnDefinition('entry_id', type: ColumnType::Text),
                    new ColumnDefinition('tenant_id', type: ColumnType::Text),
                    new ColumnDefinition('actor_id', type: ColumnType::Text),
                    new ColumnDefinition('operation', type: ColumnType::Text),
                    new ColumnDefinition('target', type: ColumnType::Text),
                    new ColumnDefinition('occurred_at', type: ColumnType::Int64),
                    new ColumnDefinition('snapshot', type: ColumnType::Text),
                ],
                primaryKey: ['entry_id'],
            ),
            new TableDefinition(
                self::BINDINGS,
                columns: [
                    new ColumnDefinition('tenant_id', type: ColumnType::Text),
                    new ColumnDefinition('principal_type', type: ColumnType::Text),
                    new ColumnDefinition('principal_id', type: ColumnType::Text),
                    new ColumnDefinition('role_id', type: ColumnType::Text),
                    new ColumnDefinition('scope_type', type: ColumnType::Text),
                    new ColumnDefinition('scope_id', type: ColumnType::Text),
                    new ColumnDefinition('active', type: ColumnType::Boolean),
                    new ColumnDefinition('expires_at', type: ColumnType::Int64, nullable: true),
                ],
                primaryKey: ['tenant_id', 'principal_type', 'principal_id', 'role_id', 'scope_type', 'scope_id'],
            ),
            new TableDefinition(
                self::GROUPS,
                columns: [
                    new ColumnDefinition('tenant_id', type: ColumnType::Text),
                    new ColumnDefinition('group_id', type: ColumnType::Text),
                    new ColumnDefinition('active', type: ColumnType::Boolean),
                ],
                primaryKey: ['tenant_id', 'group_id'],
                foreignKeys: ['owner' => new ForeignKeyDefinition(self::TENANTS, ['tenant_id' => 'tenant_id'])],
            ),
            new TableDefinition(
                self::MEMBERSHIPS,
                columns: [
                    new ColumnDefinition('tenant_id', type: ColumnType::Text),
                    new ColumnDefinition('group_id', type: ColumnType::Text),
                    new ColumnDefinition('subject_id', type: ColumnType::Text),
                    new ColumnDefinition('active', type: ColumnType::Boolean),
                ],
                primaryKey: ['tenant_id', 'group_id', 'subject_id'],
            ),
            new TableDefinition(
                self::ROLE_PERMISSIONS,
                columns: [
                    new ColumnDefinition('tenant_id', type: ColumnType::Text),
                    new ColumnDefinition('role_id', type: ColumnType::Text),
                    new ColumnDefinition('permission', type: ColumnType::Text),
                ],
                primaryKey: ['tenant_id', 'role_id', 'permission'],
                foreignKeys: ['owner' => new ForeignKeyDefinition(self::ROLES, [
                    'tenant_id' => 'tenant_id',
                    'role_id' => 'role_id',
                ])],
            ),
            new TableDefinition(
                self::ROLES,
                columns: [
                    new ColumnDefinition('tenant_id', type: ColumnType::Text),
                    new ColumnDefinition('role_id', type: ColumnType::Text),
                    new ColumnDefinition('name', type: ColumnType::Text),
                    new ColumnDefinition('active', type: ColumnType::Boolean),
                ],
                primaryKey: ['tenant_id', 'role_id'],
                foreignKeys: ['owner' => new ForeignKeyDefinition(self::TENANTS, ['tenant_id' => 'tenant_id'])],
            ),
            new TableDefinition(
                self::SUBJECTS,
                columns: [
                    new ColumnDefinition('tenant_id', type: ColumnType::Text),
                    new ColumnDefinition('subject_id', type: ColumnType::Text),
                    new ColumnDefinition('active', type: ColumnType::Boolean),
                ],
                primaryKey: ['tenant_id', 'subject_id'],
                foreignKeys: ['owner' => new ForeignKeyDefinition(self::TENANTS, ['tenant_id' => 'tenant_id'])],
            ),
            new TableDefinition(
                self::TENANTS,
                columns: [
                    new ColumnDefinition('tenant_id', type: ColumnType::Text),
                ],
                primaryKey: ['tenant_id'],
            ),
        ]);
    }

    /** @return list<SchemaMappedRowSet> */
    public static function rowSets(): array
    {
        return [
            new SchemaMappedRowSet(self::AUDIT),
            new SchemaMappedRowSet(self::BINDINGS),
            new SchemaMappedRowSet(self::GROUPS, owner: 'owner'),
            new SchemaMappedRowSet(self::MEMBERSHIPS),
            new SchemaMappedRowSet(self::ROLE_PERMISSIONS, owner: 'owner'),
            new SchemaMappedRowSet(self::ROLES, owner: 'owner'),
            new SchemaMappedRowSet(self::SUBJECTS, owner: 'owner'),
            new SchemaMappedRowSet(self::TENANTS),
        ];
    }
}
