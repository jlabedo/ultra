<?php

declare(strict_types=1);

namespace IamExample;

use Doctrine\DBAL\Connection;
use IamExample\Domain\LocalId;
use IamExample\Domain\Permission;
use IamExample\Domain\Scope;
use Psr\Clock\ClockInterface;

/** Policy decision point: one indexed SQL query, no state hydration or permission cache. */
final readonly class Authorization
{
    public function __construct(
        private Connection $connection,
        private ClockInterface $clock,
    ) {}

    public function allows(LocalId $subject, Permission $permission, Scope $resource): bool
    {
        return 1
        === (int) $this->connection->fetchOne(
            <<<'SQL'
                WITH principals AS (
                    SELECT 'subject' AS type, subject_id AS id
                    FROM iam_subjects WHERE tenant_id = ? AND subject_id = ? AND active
                    UNION ALL
                    SELECT 'group', m.group_id
                    FROM iam_memberships m
                    JOIN iam_subjects s ON s.tenant_id = m.tenant_id AND s.subject_id = m.subject_id AND s.active
                    JOIN iam_groups g ON g.tenant_id = m.tenant_id AND g.group_id = m.group_id AND g.active
                    WHERE m.tenant_id = ? AND m.subject_id = ? AND m.active
                )
                SELECT CASE WHEN EXISTS (
                    SELECT 1 FROM principals p
                    JOIN iam_bindings b ON b.tenant_id = ? AND b.principal_type = p.type AND b.principal_id = p.id
                    JOIN iam_roles r ON r.tenant_id = b.tenant_id AND r.role_id = b.role_id AND r.active
                    JOIN iam_role_permissions rp ON rp.tenant_id = r.tenant_id AND rp.role_id = r.role_id
                    WHERE b.active AND (b.expires_at IS NULL OR b.expires_at > ?)
                      AND rp.permission = ?
                      AND ((b.scope_type = 'tenant' AND b.scope_id = '*') OR (b.scope_type = ? AND b.scope_id = ?))
                ) THEN 1 ELSE 0 END
                SQL,
            [
                $subject->tenantId,
                $subject->localId,
                $subject->tenantId,
                $subject->localId,
                $subject->tenantId,
                $this->clock->now()->getTimestamp(),
                $permission->value,
                $resource->type,
                $resource->id,
            ],
        );
    }

    public function require(LocalId $subject, Permission $permission, Scope $resource): void
    {
        if (!$this->allows($subject, $permission, $resource)) {
            throw new AccessDenied('The subject is not authorized for this action.');
        }
    }
}
