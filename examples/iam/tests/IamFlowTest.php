<?php

declare(strict_types=1);

namespace IamExample\Tests;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Tools\DsnParser;
use IamExample\AccessDenied;
use IamExample\Domain\BindingId;
use IamExample\Domain\LocalId;
use IamExample\Domain\MembershipId;
use IamExample\Domain\Permission;
use IamExample\Domain\Scope;
use Ultra\UnitOfWork\DoctrineTransactionManager;
use Doctrine\DBAL\TransactionIsolationLevel;
use IamExample\Infrastructure\Runtime;
use IamExample\Infrastructure\Schema;
use IamExample\State\RoleState;
use IamExample\State\BindingState;
use IamExample\State\GroupState;
use IamExample\State\MembershipState;
use IamExample\State\SubjectState;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Ultra\Time\StatickClock;
use Ultra\UnitOfWork\Exception\ConcurrencyException;
use Ultra\UnitOfWork\TransactionManager;

/** Tests use an isolated Postgres schema, including a second connection for competing decisions. */
final class IamFlowTest extends TestCase
{
    private Connection $connection;
    private Connection $other;
    private string $schema;
    private Runtime $runtime;
    private StatickClock $clock;
    private LocalId $admin;
    private LocalId $ada;

    protected function setUp(): void
    {
        $dsn = getenv('ULTRA_TEST_POSTGRES_DSN');
        if (false === $dsn || '' === $dsn) {
            self::markTestSkipped('Set ULTRA_TEST_POSTGRES_DSN to run IAM Postgres tests.');
        }
        $params = new DsnParser(['postgres' => 'pgsql', 'postgresql' => 'pgsql'])->parse($dsn);
        $this->connection = DriverManager::getConnection($params);
        $this->other = DriverManager::getConnection($params);
        $this->schema = 'iam_test_'.bin2hex(random_bytes(8));
        $this->connection->executeStatement('CREATE SCHEMA '.$this->schema);
        foreach ([$this->connection, $this->other] as $connection) {
            $connection->executeStatement('SET search_path TO '.$this->schema);
        }
        Schema::install($this->connection);
        $this->clock = new StatickClock(new \DateTimeImmutable('2026-10-05 12:00:00 UTC'));
        $this->runtime = new Runtime($this->connection, $this->clock);
        $this->admin = new LocalId('acme', 'admin');
        $this->ada = new LocalId('acme', 'ada');
        $this->runtime->provisioning->createTenant($this->admin);
        $this->runtime->administration->setSubject($this->admin, 'ada');
    }

    protected function tearDown(): void
    {
        if (isset($this->connection)) {
            $this->other->close();
            $this->connection->executeStatement('DROP SCHEMA '.$this->schema.' CASCADE');
            $this->connection->close();
        }
    }

    public function test_unknown_subjects_and_unassigned_permissions_are_denied(): void
    {
        self::assertFalse($this->allows($this->ada));
        self::assertFalse($this->allows(new LocalId('acme', 'unknown')));
        self::assertFalse($this->allows($this->admin));
        self::assertTrue($this->runtime->authorization->allows($this->admin, Permission::ManageIam, Scope::tenant()));
        $this->expectException(AccessDenied::class);
        $this->runtime->authorization->require($this->ada, Permission::ReadDocument, Scope::resource('document', 'doc-1'));
    }

    public function test_exact_resource_scope_and_tenant_scope_and_union_of_roles(): void
    {
        $reader = $this->readerBinding(Scope::resource('document', 'doc-1'));
        $this->runtime->administration->grant($this->admin, $reader);
        self::assertTrue($this->allows($this->ada));
        self::assertFalse($this->allows($this->ada, Scope::resource('document', 'doc-2')));
        self::assertFalse($this->allows($this->ada, Scope::resource('invoice', 'doc-1')));
        self::assertFalse($this->runtime->authorization->allows($this->ada, Permission::EditDocument, Scope::resource('document', 'doc-1')));
        $this->runtime->administration->defineRole($this->admin, 'editor', 'Editor', [Permission::EditDocument]);
        $this->runtime->administration->grant($this->admin, BindingId::forPrincipal($this->ada, 'subject', 'editor', Scope::tenant()));
        self::assertTrue($this->runtime->authorization->allows($this->ada, Permission::EditDocument, Scope::resource('document', 'doc-2')));
        self::assertTrue($this->allows($this->ada));
    }

    public function test_group_permissions_and_membership_revocation(): void
    {
        $this->runtime->administration->setGroup($this->admin, 'staff');
        $membership = new MembershipId('acme', 'staff', 'ada');
        $this->runtime->administration->setMembership($this->admin, $membership, true);
        $this->readerBinding();
        $binding = BindingId::forPrincipal(new LocalId('acme', 'staff'), 'group', 'reader', Scope::tenant());
        $this->runtime->administration->grant($this->admin, $binding);
        self::assertTrue($this->allows($this->ada));
        $this->runtime->administration->setMembership($this->admin, $membership, false);
        self::assertFalse($this->allows($this->ada));
    }

    public function test_group_suspension_denies_its_permissions(): void
    {
        $this->runtime->administration->setGroup($this->admin, 'staff');
        $this->runtime->administration->setMembership($this->admin, new MembershipId('acme', 'staff', 'ada'), true);
        $this->readerBinding();
        $this->runtime->administration->grant($this->admin, BindingId::forPrincipal(new LocalId('acme', 'staff'), 'group', 'reader', Scope::tenant()));
        $this->runtime->administration->setGroup($this->admin, 'staff', false);
        self::assertFalse($this->allows($this->ada));
    }

    public function test_revocation_and_regrant_are_immediately_visible_and_idempotent(): void
    {
        $binding = $this->readerBinding();
        $commands = $this->runtime->administration;
        $commands->grant($this->admin, $binding);
        $commands->grant($this->admin, $binding);
        self::assertTrue($this->allows($this->ada));
        $commands->revoke($this->admin, $binding);
        $commands->revoke($this->admin, $binding);
        self::assertFalse($this->allows($this->ada));
        $commands->grant($this->admin, $binding);
        self::assertTrue($this->allows($this->ada));
        self::assertSame(1, (int) $this->connection->fetchOne("SELECT count(*) FROM iam_bindings WHERE principal_id = 'ada'"));
    }

    public function test_expiration_at_the_boundary_denies_access(): void
    {
        $binding = $this->readerBinding();
        $expires = $this->clock->now()->getTimestamp() + 60;
        $this->runtime->administration->grant($this->admin, $binding, $expires);
        self::assertTrue($this->allows($this->ada));
        $this->clock->setNow($this->clock->now()->setTimestamp($expires));
        self::assertFalse($this->allows($this->ada));
        $this->expectException(\InvalidArgumentException::class);
        $this->runtime->administration->grant($this->admin, $binding, $expires);
    }

    public function test_subject_suspension_denies_direct_and_group_permissions(): void
    {
        $this->runtime->administration->grant($this->admin, $this->readerBinding());
        $this->runtime->administration->setGroup($this->admin, 'staff');
        $this->runtime->administration->setMembership($this->admin, new MembershipId('acme', 'staff', 'ada'), true);
        $this->runtime->administration->grant($this->admin, BindingId::forPrincipal(new LocalId('acme', 'staff'), 'group', 'reader', Scope::tenant()));
        $this->runtime->administration->setSubject($this->admin, 'ada', false);
        self::assertFalse($this->allows($this->ada));
    }

    public function test_replacing_permission_collection_and_disabling_role_revoke_access(): void
    {
        $this->runtime->administration->grant($this->admin, $this->readerBinding());
        $this->runtime->administration->defineRole($this->admin, 'reader', 'Reader', [Permission::EditDocument]);
        self::assertFalse($this->allows($this->ada));
        self::assertSame(['documents:edit'], $this->connection->fetchFirstColumn("SELECT permission FROM iam_role_permissions WHERE role_id = 'reader'"));
        $this->runtime->administration->defineRole($this->admin, 'reader', 'Reader', [Permission::ReadDocument], false);
        self::assertFalse($this->allows($this->ada));
    }

    public function test_identical_identifiers_in_another_tenant_do_not_share_permissions(): void
    {
        $this->runtime->administration->grant($this->admin, $this->readerBinding());
        $otherAdmin = new LocalId('other', 'admin');
        $this->runtime->provisioning->createTenant($otherAdmin);
        $this->runtime->administration->setSubject($otherAdmin, 'ada');
        $this->runtime->administration->defineRole($otherAdmin, 'reader', 'Reader', [Permission::ReadDocument]);
        self::assertFalse($this->allows(new LocalId('other', 'ada')));
        $before = $this->auditCount();
        try {
            $this->runtime->administration->grant($this->admin, BindingId::forPrincipal(new LocalId('other', 'ada'), 'subject', 'reader', Scope::tenant()));
            self::fail('Cross-tenant grant must be denied.');
        } catch (AccessDenied) {
            self::assertSame($before, $this->auditCount());
        }
    }

    public function test_unprivileged_subject_cannot_grant_itself_administration(): void
    {
        $before = $this->auditCount();
        try {
            $this->runtime->administration->grant($this->ada, BindingId::forPrincipal($this->ada, 'subject', 'administrator', Scope::tenant()));
            self::fail('Self-elevation must be denied.');
        } catch (AccessDenied) {
            self::assertSame($before, $this->auditCount());
            self::assertFalse($this->runtime->authorization->allows($this->ada, Permission::ManageIam, Scope::tenant()));
        }
    }

    public function test_resource_scoped_administration_does_not_allow_tenant_management(): void
    {
        $this->runtime->administration->grant($this->admin, BindingId::forPrincipal($this->ada, 'subject', 'administrator', Scope::resource('document', 'doc-1')));
        $this->expectException(AccessDenied::class);
        $this->runtime->administration->setSubject($this->ada, 'intruder');
    }

    public function test_missing_reference_rejects_grant_without_partial_changes(): void
    {
        $before = $this->auditCount();
        try {
            $this->runtime->administration->grant($this->admin, BindingId::forPrincipal($this->ada, 'subject', 'missing', Scope::tenant()));
            self::fail('Missing role must be rejected.');
        } catch (\DomainException) {
            self::assertSame($before, $this->auditCount());
            self::assertSame(0, (int) $this->connection->fetchOne("SELECT count(*) FROM iam_bindings WHERE principal_id = 'ada'"));
        }
        $this->runtime->administration->grant($this->admin, $this->readerBinding());
        self::assertTrue($this->allows($this->ada));
    }

    public function test_provisioning_cannot_reset_an_existing_tenant(): void
    {
        $this->expectException(\DomainException::class);
        $this->runtime->provisioning->createTenant(new LocalId('acme', 'intruder'));
    }

    public function test_every_committed_change_has_an_audit_entry_with_the_actor(): void
    {
        $binding = $this->readerBinding();
        $this->runtime->administration->grant($this->admin, $binding);
        $this->runtime->administration->revoke($this->admin, $binding);
        self::assertSame(5, $this->auditCount());
        self::assertSame(['admin'], $this->connection->fetchFirstColumn('SELECT DISTINCT actor_id FROM iam_audit'));
        $entry = $this->connection->fetchAssociative("SELECT * FROM iam_audit WHERE operation = 'binding.revoked'");
        self::assertSame('ada', json_decode($entry['target'], true, flags: JSON_THROW_ON_ERROR)['principalId']);
        self::assertFalse(json_decode($entry['snapshot'], true, flags: JSON_THROW_ON_ERROR)['active']);
        $role = $this->connection->fetchOne("SELECT snapshot FROM iam_audit WHERE operation = 'role.defined'");
        self::assertSame([['permission' => 'documents:read']], json_decode($role, true, flags: JSON_THROW_ON_ERROR)['permissions']);
    }

    public function test_audit_failure_rolls_back_the_domain_change_and_clears_pending_work(): void
    {
        $this->connection->executeStatement(<<<'SQL'
            CREATE FUNCTION reject_audit() RETURNS trigger LANGUAGE plpgsql AS $$
            BEGIN RAISE EXCEPTION 'audit unavailable'; END $$;
            SQL);
        $this->connection->executeStatement('CREATE TRIGGER reject_audit BEFORE INSERT ON iam_audit FOR EACH ROW EXECUTE FUNCTION reject_audit()');
        try {
            $this->runtime->administration->defineRole($this->admin, 'failed', 'Failed', [Permission::ReadDocument]);
            self::fail('Audit failure must roll back.');
        } catch (\Doctrine\DBAL\Exception) {
            self::assertSame(0, (int) $this->connection->fetchOne("SELECT count(*) FROM iam_roles WHERE role_id = 'failed'"));
            self::assertSame(2, $this->auditCount());
        }
        $this->connection->executeStatement('DROP TRIGGER reject_audit ON iam_audit');
        $this->runtime->administration->setSubject($this->admin, 'next');
        self::assertSame(0, (int) $this->connection->fetchOne("SELECT count(*) FROM iam_roles WHERE role_id = 'failed'"));
    }

    public function test_competing_revocation_prevents_a_previously_authorized_command_from_committing(): void
    {
        $this->runtime->administration->grant($this->admin, BindingId::forPrincipal($this->ada, 'subject', 'administrator', Scope::tenant()));
        $competitor = new Runtime($this->other, $this->clock);
        $stale = $this->runtimeBeforeFlush(function () use ($competitor): void {
            $competitor->administration->revoke($this->admin, BindingId::forPrincipal($this->ada, 'subject', 'administrator', Scope::tenant()));
        });
        $before = $this->auditCount();
        try {
            $stale->administration->setSubject($this->ada, 'intruder');
            self::fail('Revocation must invalidate the pending decision.');
        } catch (ConcurrencyException) {
            self::assertSame(0, (int) $this->connection->fetchOne("SELECT count(*) FROM iam_subjects WHERE subject_id = 'intruder'"));
            self::assertSame($before + 1, $this->auditCount());
        }
    }

    public function test_permission_collection_revocation_is_rechecked_at_commit(): void
    {
        $this->runtime->administration->defineRole($this->admin, 'delegated-admin', 'Delegated administrator', [Permission::ManageIam]);
        $this->runtime->administration->grant($this->admin, BindingId::forPrincipal($this->ada, 'subject', 'delegated-admin', Scope::tenant()));
        $competitor = new Runtime($this->other, $this->clock);
        $stale = $this->runtimeBeforeFlush(fn () => $competitor->administration->defineRole($this->admin, 'delegated-admin', 'Delegated administrator', []));
        $this->expectException(ConcurrencyException::class);
        $stale->administration->setGroup($this->ada, 'unauthorized');
    }

    public function test_administrative_permission_expiring_before_flush_is_rechecked(): void
    {
        $expires = $this->clock->now()->getTimestamp() + 1;
        $this->runtime->administration->grant($this->admin, BindingId::forPrincipal($this->ada, 'subject', 'administrator', Scope::tenant()), $expires);
        $stale = $this->runtimeBeforeFlush(fn () => $this->clock->setNow($this->clock->now()->setTimestamp($expires)));
        $this->expectException(ConcurrencyException::class);
        $stale->administration->setSubject($this->ada, 'unauthorized');
    }

    public function test_grant_only_depends_on_referenced_role_existence(): void
    {
        $binding = $this->readerBinding();
        $competitor = new Runtime($this->other, $this->clock);
        $pending = $this->runtimeBeforeFlush(fn () => $competitor->administration->defineRole($this->admin, 'reader', 'Updated reader', [Permission::EditDocument]));
        $pending->administration->grant($this->admin, $binding);
        self::assertFalse($this->allows($this->ada));
        self::assertTrue($this->runtime->authorization->allows($this->ada, Permission::EditDocument, Scope::resource('document', 'doc-1')));
    }

    public function test_competing_role_updates_use_optimistic_collection_expectations(): void
    {
        $this->readerBinding();
        $id = new LocalId('acme', 'reader');
        $original = $this->runtime->states->observe(RoleState::class, $id);
        $this->runtime->states->save(new RoleState($id, 'Stale', $original->permissions));
        (new Runtime($this->other, $this->clock))->administration->defineRole($this->admin, 'reader', 'Reader', [Permission::EditDocument]);
        $this->expectException(ConcurrencyException::class);
        $this->runtime->runner->run(static fn () => null);
    }

    public function test_commands_cannot_be_batched_over_stale_sql_authorization(): void
    {
        $this->expectException(\LogicException::class);
        $this->runtime->runner->run(fn () => $this->runtime->administration->setSubject($this->admin, 'batched'));
    }

    public function test_commands_reject_an_external_database_transaction(): void
    {
        $this->connection->beginTransaction();
        try {
            $this->runtime->administration->setSubject($this->admin, 'external');
            self::fail('IAM must own its transaction for authorization replay.');
        } catch (\LogicException) {
            self::assertSame(0, (int) $this->connection->fetchOne("SELECT count(*) FROM iam_subjects WHERE subject_id = 'external'"));
        } finally {
            $this->connection->rollBack();
        }
        $this->runtime->administration->setSubject($this->admin, 'after');
        self::assertSame(0, (int) $this->connection->fetchOne("SELECT count(*) FROM iam_subjects WHERE subject_id = 'external'"));
    }

    public static function guardedMutations(): iterable
    {
        foreach (['subject', 'group', 'membership', 'role', 'permissions', 'binding', 'expiration'] as $mutation) {
            yield $mutation => [$mutation];
        }
    }

    #[DataProvider('guardedMutations')]
    public function test_direct_state_writers_wait_for_authorization_readers_in_their_tenant(string $mutation): void
    {
        $this->runtime->administration->setGroup($this->admin, 'staff');
        $this->runtime->administration->setMembership($this->admin, new MembershipId('acme', 'staff', 'ada'), true);
        $writer = new Runtime($this->other, $this->clock);
        $write = function () use ($writer, $mutation): void {
            $writer->runner->run(function () use ($writer, $mutation): void {
                [$class, $id] = match ($mutation) {
                    'subject' => [SubjectState::class, $this->ada],
                    'group' => [GroupState::class, new LocalId('acme', 'staff')],
                    'membership' => [MembershipState::class, new MembershipId('acme', 'staff', 'ada')],
                    'role', 'permissions' => [RoleState::class, new LocalId('acme', 'administrator')],
                    default => [BindingState::class, BindingId::forPrincipal($this->admin, 'subject', 'administrator', Scope::tenant())],
                };
                $original = $writer->states->observe($class, $id);
                $next = match ($mutation) {
                    'subject' => new SubjectState($id, false),
                    'group' => new GroupState($id, false),
                    'membership' => new MembershipState($id, false),
                    'role' => new RoleState($id, $original->name, $original->permissions, false),
                    'permissions' => new RoleState($id, $original->name, [], $original->active),
                    'expiration' => new BindingState($id, true, $this->clock->now()->getTimestamp() + 60),
                    default => new BindingState($id, false),
                };
                $writer->states->save($next);
            });
        };
        $this->other->executeStatement("SET lock_timeout = '50ms'");
        self::assertTrue($this->runtime->authorizationGuard->observe('acme', fn (): bool =>
            $this->runtime->authorization->allows($this->admin, Permission::ManageIam, Scope::tenant())));
        $this->runtime->states->expectQuery(function () use ($writer, $write): bool {
            // All guards are held when query expectations are replayed, before any writes.
            try {
                $write();
                self::fail('A direct writer must participate without calling Administration or a query.');
            } catch (\Doctrine\DBAL\Exception\DriverException $exception) {
                self::assertSame('55P03', $exception->getSQLState());
                self::assertFalse($this->other->isTransactionActive());
            }
            // The same declarations protect another tenant independently, including provisioning.
            $writer->provisioning->createTenant(new LocalId('other', 'admin'));
            self::assertTrue($writer->authorization->allows(new LocalId('other', 'admin'), Permission::ManageIam, Scope::tenant()));
            // Ordinary authorization SQL reads do not wait for a guard.
            self::assertTrue($writer->authorization->allows($this->admin, Permission::ManageIam, Scope::tenant()));

            return true;
        }, true);
        $this->runtime->runner->run(static fn () => null);
        // Completion releases the reader's guard; retrying the direct writer can commit.
        $write();
        self::assertFalse($this->other->isTransactionActive());
    }

    public function test_guarded_authorization_rejects_a_pending_permission_revocation_before_reading_sql(): void
    {
        $id = new LocalId('acme', 'administrator');
        $role = $this->runtime->states->observe(RoleState::class, $id);
        $this->runtime->states->save(new RoleState($id, $role->name, []));
        try {
            $this->runtime->authorizationGuard->observe('acme', static fn (): bool => throw new \RuntimeException('Must not read stale SQL'));
            self::fail('An initial guarded read must not ignore a pending revocation.');
        } catch (\Ultra\StateStore\Exception\PendingQueryChangesException $exception) {
            self::assertSame('iam_authorization', $exception->guardName);
            self::assertSame('acme', $exception->guardValue);
        }
        $this->runtime->runner->run(static fn () => null);
        self::assertFalse($this->runtime->authorization->allows($this->admin, Permission::ManageIam, Scope::tenant()));
    }

    private function runtimeBeforeFlush(callable $beforeFlush): Runtime
    {
        $transactions = new class($this->connection, $beforeFlush) implements TransactionManager {
            public function __construct(private Connection $connection, private mixed $beforeFlush) {}
            public function transactional(callable $callback): mixed
            {
                ($this->beforeFlush)();
                return new DoctrineTransactionManager($this->connection, TransactionIsolationLevel::READ_COMMITTED)->transactional($callback);
            }
        };
        return new Runtime($this->connection, $this->clock, $transactions);
    }

    private function readerBinding(?Scope $scope = null): BindingId
    {
        $this->runtime->administration->defineRole($this->admin, 'reader', 'Reader', [Permission::ReadDocument, Permission::ReadDocument]);
        return BindingId::forPrincipal($this->ada, 'subject', 'reader', $scope ?? Scope::tenant());
    }

    private function allows(LocalId $subject, ?Scope $scope = null): bool
    {
        return $this->runtime->authorization->allows($subject, Permission::ReadDocument, $scope ?? Scope::resource('document', 'doc-1'));
    }

    private function auditCount(): int
    {
        return (int) $this->connection->fetchOne('SELECT count(*) FROM iam_audit');
    }
}
