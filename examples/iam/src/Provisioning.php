<?php

declare(strict_types=1);

namespace IamExample;

use IamExample\Domain\BindingId;
use IamExample\Domain\LocalId;
use IamExample\Domain\Permission;
use IamExample\Domain\Scope;
use IamExample\State\AuditEntry;
use IamExample\State\BindingState;
use IamExample\State\PermissionState;
use IamExample\State\RoleState;
use IamExample\State\SubjectState;
use IamExample\State\TenantState;
use Psr\Clock\ClockInterface;
use Ultra\StateStore\StateStore;
use Ultra\UnitOfWork\TransactionalUnitOfWorkRunner;

/** Trusted provisioning port, kept separate from the authenticated administration API. */
final readonly class Provisioning
{
    public function __construct(private StateStore $states, private TransactionalUnitOfWorkRunner $runner, private ClockInterface $clock) {}

    public function createTenant(LocalId $initialAdministrator): void
    {
        if ($this->runner->hasActiveTransaction()) {
            throw new \LogicException('Provisioning requires its own unit of work.');
        }
        $this->runner->run(function () use ($initialAdministrator): void {
            $tenantId = $initialAdministrator->tenantId;
            if (null !== $this->states->observe(TenantState::class, $tenantId)) {
                throw new \DomainException('The tenant already exists.');
            }
            $tenant = new TenantState($tenantId);
            $this->states->save($tenant);
            $this->states->save(new SubjectState($initialAdministrator));
            $this->states->save(new RoleState(
                new LocalId($tenantId, 'administrator'), 'IAM administrator',
                [new PermissionState(Permission::ManageIam->value)],
            ));
            $this->states->save(new BindingState(BindingId::forPrincipal($initialAdministrator, 'subject', 'administrator', Scope::tenant()), true));
            $this->states->save(new AuditEntry(bin2hex(random_bytes(16)), $tenantId, $initialAdministrator->localId,
                'tenant.provisioned', json_encode($initialAdministrator, JSON_THROW_ON_ERROR), $this->clock->now()->getTimestamp(),
                json_encode(['administrator' => $initialAdministrator, 'permission' => Permission::ManageIam->value], JSON_THROW_ON_ERROR)));
        });
    }
}
