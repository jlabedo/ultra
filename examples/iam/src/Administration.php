<?php

declare(strict_types=1);

namespace IamExample;

use IamExample\Domain\BindingId;
use IamExample\Domain\LocalId;
use IamExample\Domain\MembershipId;
use IamExample\Domain\Permission;
use IamExample\Domain\Scope;
use IamExample\State\AuditEntry;
use IamExample\State\BindingState;
use IamExample\State\GroupState;
use IamExample\State\MembershipState;
use IamExample\State\PermissionState;
use IamExample\State\RoleState;
use IamExample\State\SubjectState;
use IamExample\State\TenantState;
use Psr\Clock\ClockInterface;
use Ultra\StateStore\StateStore;
use Ultra\StateStore\QueryGuard;
use Ultra\UnitOfWork\TransactionalUnitOfWorkRunner;

/** Command boundary. The actor is a verified identity supplied by the transport, never by a request body. */
final readonly class Administration
{
    public function __construct(
        private StateStore $states,
        private TransactionalUnitOfWorkRunner $runner,
        private Authorization $authorization,
        private QueryGuard $authorizationGuard,
        private ClockInterface $clock,
    ) {}

    /** @param list<Permission> $permissions */
    public function defineRole(LocalId $actor, string $roleId, string $name, array $permissions, bool $active = true): void
    {
        $id = new LocalId($actor->tenantId, $roleId);
        if ('' === trim($name)) {
            throw new \InvalidArgumentException('A role name is required.');
        }
        $values = [];
        foreach ($permissions as $permission) {
            if (!$permission instanceof Permission) {
                throw new \InvalidArgumentException('Unknown permission.');
            }
            $values[$permission->value] = new PermissionState($permission->value);
        }
        ksort($values);
        $this->write($actor, 'role.defined', $id, function () use ($id, $name, $values, $active): RoleState {
            $this->states->observe(RoleState::class, $id);
            return new RoleState($id, $name, array_values($values), $active);
        });
    }

    public function setSubject(LocalId $actor, string $subjectId, bool $active = true): void
    {
        $id = new LocalId($actor->tenantId, $subjectId);
        $this->write($actor, 'subject.updated', $id, function () use ($id, $active): SubjectState {
            $this->states->observe(SubjectState::class, $id);
            return new SubjectState($id, $active);
        });
    }

    public function setGroup(LocalId $actor, string $groupId, bool $active = true): void
    {
        $id = new LocalId($actor->tenantId, $groupId);
        $this->write($actor, 'group.updated', $id, function () use ($id, $active): GroupState {
            $this->states->observe(GroupState::class, $id);
            return new GroupState($id, $active);
        });
    }

    public function setMembership(LocalId $actor, MembershipId $id, bool $active): void
    {
        $this->sameTenant($actor, $id->tenantId);
        $this->write($actor, 'membership.updated', $id, function () use ($id, $active): MembershipState {
            $this->requireExisting(GroupState::class, new LocalId($id->tenantId, $id->groupId));
            $this->requireExisting(SubjectState::class, new LocalId($id->tenantId, $id->subjectId));
            $this->states->observe(MembershipState::class, $id);
            return new MembershipState($id, $active);
        });
    }

    public function grant(LocalId $actor, BindingId $id, ?int $expiresAt = null): void
    {
        $this->sameTenant($actor, $id->tenantId);
        if (null !== $expiresAt && $expiresAt <= $this->clock->now()->getTimestamp()) {
            throw new \InvalidArgumentException('A grant must expire in the future.');
        }
        $this->write($actor, 'binding.granted', $id, function () use ($id, $expiresAt): BindingState {
            $this->requireExisting(RoleState::class, new LocalId($id->tenantId, $id->roleId));
            $this->requireExisting('subject' === $id->principalType ? SubjectState::class : GroupState::class, new LocalId($id->tenantId, $id->principalId));
            $this->states->observe(BindingState::class, $id);
            return new BindingState($id, true, $expiresAt);
        });
    }

    public function revoke(LocalId $actor, BindingId $id): void
    {
        $this->sameTenant($actor, $id->tenantId);
        $this->write($actor, 'binding.revoked', $id, function () use ($id): BindingState {
            $binding = $this->existing(BindingState::class, $id);
            return new BindingState($id, false, $binding->expiresAt);
        });
    }

    private function write(LocalId $actor, string $operation, object $target, callable $change): void
    {
        // SQL reads committed data. Enforce one command per unit of work so a queued revocation cannot be ignored.
        if ($this->runner->hasActiveTransaction()) {
            throw new \LogicException('IAM commands cannot be batched inside another unit of work.');
        }
        $this->runner->run(function () use ($actor, $operation, $target, $change): void {
            $this->requireExisting(TenantState::class, $actor->tenantId);
            // An earlier successful check must not let an administrator recreate access after their revocation.
            $allowed = $this->authorizationGuard->observe(
                $actor->tenantId,
                fn (): bool => $this->authorization->allows($actor, Permission::ManageIam, Scope::tenant()),
            );
            if (!$allowed) {
                throw new AccessDenied('IAM administration requires iam:manage at tenant scope.');
            }
            $next = $change();
            $this->states->save($next);
            $this->states->save(new AuditEntry(
                bin2hex(random_bytes(16)), $actor->tenantId, $actor->localId,
                $operation, json_encode($target, JSON_THROW_ON_ERROR), $this->clock->now()->getTimestamp(),
                json_encode($next, JSON_THROW_ON_ERROR),
            ));
        });
    }

    /** @param class-string $class */
    private function requireExisting(string $class, int|string|object $id): void
    {
        if (!$this->states->observeExists($class, $id)) {
            throw new \DomainException('The referenced IAM state does not exist.');
        }
    }

    /** @param class-string $class */
    private function existing(string $class, mixed $id): object
    {
        return $this->states->observe($class, $id) ?? throw new \DomainException('The referenced IAM state does not exist.');
    }

    private function sameTenant(LocalId $actor, string $tenantId): void
    {
        if ($actor->tenantId !== $tenantId) {
            throw new AccessDenied('Cross-tenant administration is forbidden.');
        }
    }
}
