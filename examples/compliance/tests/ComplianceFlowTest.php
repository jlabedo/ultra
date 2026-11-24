<?php

declare(strict_types=1);

namespace ComplianceExample\Tests;

use ComplianceExample\Refresh\Scope;
use ComplianceExample\Requirements;
use ComplianceExample\Runtime;
use ComplianceExample\Schema;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception\DriverException;
use PHPUnit\Framework\TestCase;
use Ultra\Coordination_Postgres\Tracking\PostgresDirtyKeyTracker;
use Ultra\Testing\PostgresConnectionSupport;

final class ComplianceFlowTest extends TestCase
{
    private Connection $connection;
    private string $schema;
    private Runtime $runtime;

    protected function setUp(): void
    {
        $dsn = getenv('ULTRA_TEST_POSTGRES_DSN');
        if (false === $dsn || '' === $dsn) {
            self::markTestSkipped('Set ULTRA_TEST_POSTGRES_DSN to run compliance Postgres tests.');
        }
        $this->connection = PostgresConnectionSupport::sharedConnection($dsn, self::class);
        $this->schema = 'compliance_test_' . bin2hex(random_bytes(8));
        $this->connection->transactional(function (Connection $connection): void {
            $connection->executeStatement('CREATE SCHEMA ' . $this->schema);
            $connection->executeStatement('SET search_path TO ' . $this->schema);
            Schema::install($connection);
        });
        $this->runtime = new Runtime($this->connection, subjectBatchSize: 1);
    }

    protected function tearDown(): void
    {
        if (isset($this->connection)) {
            PostgresConnectionSupport::resetSharedConnection($this->connection);
            $this->connection->executeStatement('RESET search_path');
            $this->connection->executeStatement('DROP SCHEMA IF EXISTS ' . $this->schema . ' CASCADE');
        }
    }

    public function test_install_creates_the_mapped_table_keys_and_indexes_in_column_order(): void
    {
        self::assertSame(
            [
                ['compliance_eligibility_by_qualification', false, false, 'account_id,qualification_id,subject_id'],
                ['compliance_eligibility_pkey',             true,  true,  'account_id,subject_id,qualification_id'],
                ['compliance_evidence_by_type',             false, false, 'account_id,subject_id,document_type'],
                ['compliance_evidence_pkey',                true,  true,  'account_id,subject_id,evidence_id'],
                ['compliance_qualifications_pkey',          true,  true,  'account_id,qualification_id'],
            ],
            $this->connection->fetchAllNumeric(<<<'SQL'
                SELECT index_class.relname, idx.indisprimary, idx.indisunique,
                       string_agg(col.attname, ',' ORDER BY key_columns.position)
                FROM pg_index idx
                JOIN pg_class table_class ON table_class.oid = idx.indrelid
                JOIN pg_class index_class ON index_class.oid = idx.indexrelid
                CROSS JOIN LATERAL unnest(idx.indkey) WITH ORDINALITY AS key_columns(column_number, position)
                JOIN pg_attribute col ON col.attrelid = table_class.oid AND col.attnum = key_columns.column_number
                WHERE table_class.relnamespace = current_schema()::regnamespace
                  AND table_class.relname IN ('compliance_eligibility', 'compliance_evidence', 'compliance_qualifications')
                GROUP BY index_class.relname, idx.indisprimary, idx.indisunique
                ORDER BY index_class.relname
                SQL),
        );
    }

    public function test_source_projection_and_materialization_advance_separately(): void
    {
        $compliance = $this->runtime->compliance;
        $queries = $this->runtime->queries;
        $compliance->define('acme', 'base', new Requirements([['passport', 'id'], ['insurance']]));
        $this->runtime->project();
        $this->runtime->refresh('acme');
        self::assertSame([], $queries->subjects('acme', 'base'));

        $compliance->provide('acme', 'ada', 'p-1', 'passport');
        $compliance->provide('acme', 'ada', 'i-1', 'insurance');
        self::assertSame(0, $this->runtime->refresh('acme'));
        self::assertSame([], $queries->qualifications('acme', 'ada'));

        $this->runtime->project();
        self::assertSame(['ada'], $this->runtime->reference->subjects('acme', 'base'));
        self::assertSame([], $queries->subjects('acme', 'base'));
        $this->runtime->refresh('acme');
        self::assertSame(['ada'], $queries->subjects('acme', 'base'));
        self::assertSame(['base'], $queries->qualifications('acme', 'ada'));

        $compliance->withdraw('acme', 'ada', 'i-1');
        $this->runtime->project();
        self::assertSame([], $this->runtime->reference->qualifications('acme', 'ada'));
        self::assertSame(['ada'], $queries->subjects('acme', 'base'));
        $this->runtime->refresh('acme');
        self::assertSame([], $queries->subjects('acme', 'base'));
        self::assertSame([], $queries->qualifications('acme', 'ada'));
        self::assertSame(0, $this->runtime->refresh('acme'));
    }

    public function test_partial_recalculation_respects_alternatives_and_removes_the_last_match(): void
    {
        $compliance = $this->runtime->compliance;
        $queries = $this->runtime->queries;
        $compliance->define('acme', 'base', new Requirements([['passport', 'id'], ['insurance']]));
        $compliance->define('acme', 'insured', new Requirements([['insurance']]));
        $compliance->provide('acme', 'ada', 'p-1', 'passport');
        $compliance->provide('acme', 'ada', 'p-2', 'passport');
        $compliance->provide('acme', 'ada', 'id-1', 'id');
        $compliance->provide('acme', 'ada', 'i-1', 'insurance');
        $compliance->provide('acme', 'lin', 'id-1', 'id');
        $compliance->provide('acme', 'lin', 'i-1', 'insurance');
        $compliance->provide('acme', 'sam', 'p-1', 'passport');
        $compliance->provide('acme', 'sam', 'i-1', 'insurance');
        $compliance->withdraw('acme', 'sam', 'i-1');
        $this->runtime->project();
        $this->runtime->refresh('acme');
        self::assertSame(['ada', 'lin'], $queries->subjects('acme', 'base'));

        $compliance->withdraw('acme', 'ada', 'p-1');
        $compliance->withdraw('acme', 'ada', 'id-1');
        $this->runtime->project();
        self::assertSame(1, $this->runtime->refresh('acme'));
        self::assertSame(['base', 'insured'], $queries->qualifications('acme', 'ada'));

        $compliance->withdraw('acme', 'ada', 'p-2');
        $this->runtime->project();
        $this->runtime->refresh('acme');
        self::assertSame(['lin'], $queries->subjects('acme', 'base'));
        self::assertSame(['ada', 'lin'], $queries->subjects('acme', 'insured'));
        self::assertSame(['insured'], $queries->qualifications('acme', 'ada'));
        self::assertSame([], $queries->qualifications('acme', 'sam'));
    }

    public function test_initial_build_resumes_in_a_new_runtime_and_publishes_readiness_at_completion(): void
    {
        $compliance = $this->runtime->compliance;
        $compliance->define('acme', 'base', new Requirements([['passport']]));
        foreach (['ada', 'lin', 'sam'] as $subject) {
            $compliance->provide('acme', $subject, 'p-1', 'passport');
        }
        foreach (['ada', 'sam'] as $subject) {
            $compliance->provide('acme', $subject, 'i-1', 'insurance');
        }
        $this->runtime->project();
        $this->runtime->refresh('acme');
        self::assertSame(['ada', 'lin', 'sam'], $this->runtime->queries->subjects('acme', 'base'));

        $compliance->define('acme', 'base_v2', new Requirements([['passport'], ['insurance']]));
        $this->runtime->project();
        $this->assertQualificationBuilding('base_v2');
        self::assertSame(1, $this->runtime->refresh('acme', maxSteps: 1));
        $this->assertQualificationBuilding('base_v2');
        self::assertSame(['base'], $this->runtime->queries->qualifications('acme', 'ada'));

        // A subject added behind the initial scan's cursor is picked up by its own invalidation.
        $restarted = new Runtime($this->connection, subjectBatchSize: 1);
        $restarted->compliance->provide('acme', 'aa', 'p-1', 'passport');
        $restarted->compliance->provide('acme', 'aa', 'i-1', 'insurance');
        $restarted->project();
        $restarted->refresh('acme');
        self::assertSame(['aa', 'ada', 'sam'], $restarted->queries->subjects('acme', 'base_v2'));
        self::assertSame(['aa', 'ada', 'lin', 'sam'], $restarted->queries->subjects('acme', 'base'));
        self::assertSame(['base', 'base_v2'], $restarted->queries->qualifications('acme', 'ada'));
        self::assertSame(['base'], $restarted->queries->qualifications('acme', 'lin'));
    }

    public function test_invalidation_during_a_qualification_scan_restarts_from_the_first_page(): void
    {
        $compliance = $this->runtime->compliance;
        $compliance->provide('acme', 'ada', 'p-1', 'passport');
        $compliance->provide('acme', 'lin', 'p-1', 'passport');
        $this->runtime->project();
        $this->runtime->refresh('acme');
        $compliance->define('acme', 'base', new Requirements([['passport']]));
        $this->runtime->project();
        self::assertSame(1, $this->runtime->refresh('acme', maxSteps: 1));
        $this->assertQualificationBuilding('base');

        $dirty = new PostgresDirtyKeyTracker($this->connection, Schema::DIRTY_KEYS);
        $channel = Scope::channel('acme');
        $qualification = Scope::qualification('base');
        $subject = Scope::subject('ada');
        $dirty->bump($channel, $qualification);
        $compliance->withdraw('acme', 'ada', 'p-1');
        $this->runtime->project();

        // Finish the older scan without applying the subject change already behind its cursor.
        self::assertSame(1, $this->runtime->refresh('acme', maxSteps: 1));
        self::assertSame(['ada', 'lin'], $this->runtime->queries->subjects('acme', 'base'));
        self::assertSame(
            [$qualification => 1, $subject => 1],
            array_column($dirty->pendingForChannel($channel), 'counter', 'key'),
        );

        // The next scan starts again at Ada. Her earlier result itself is a candidate for removal.
        self::assertSame(1, $this->runtime->refresh('acme', maxSteps: 1));
        self::assertSame(['lin'], $this->runtime->queries->subjects('acme', 'base'));
        self::assertSame(
            [$qualification => 1, $subject => 1],
            array_column($dirty->pendingForChannel($channel), 'counter', 'key'),
        );
        $this->runtime->refresh('acme');
        self::assertSame([], $dirty->pendingForChannel($channel));
    }

    public function test_requirements_of_an_existing_qualification_cannot_be_changed(): void
    {
        $compliance = $this->runtime->compliance;
        $compliance->define('acme', 'base', new Requirements([['passport']]));
        $this->expectException(\DomainException::class);
        $this->expectExceptionMessage('Use a new identifier to define another version of this qualification.');
        $compliance->define('acme', 'base', new Requirements([['insurance']]));
    }

    public function test_commands_read_pending_evidence_and_discard_it_when_the_work_fails(): void
    {
        $compliance = $this->runtime->compliance;
        $compliance->define('acme', 'base', new Requirements([['passport']]));

        $failure = new \RuntimeException('Abort the application work.');
        try {
            $this->runtime->work->run(static function () use ($compliance, $failure): void {
                $compliance->provide('acme', 'ada', 'p-1', 'passport');
                $compliance->withdraw('acme', 'ada', 'p-1');

                throw $failure;
            });
            self::fail('The work must propagate its failure.');
        } catch (\RuntimeException $exception) {
            self::assertSame($failure, $exception);
        }

        $restarted = new Runtime($this->connection, subjectBatchSize: 1);
        $restarted->compliance->provide('acme', 'ada', 'p-1', 'passport');
        self::assertSame(2, $restarted->project());
        $restarted->refresh('acme');
        self::assertSame(['ada'], $restarted->queries->subjects('acme', 'base'));

        $compliance->withdraw('acme', 'ada', 'p-1');
        self::assertSame(1, $this->runtime->project());
        $this->runtime->refresh('acme');
        self::assertSame([], $this->runtime->queries->subjects('acme', 'base'));
    }

    public function test_accounts_with_the_same_local_ids_refresh_independently(): void
    {
        $compliance = $this->runtime->compliance;
        $queries = $this->runtime->queries;
        $compliance->define('acme', 'base', new Requirements([['passport']]));
        $compliance->define('other', 'base', new Requirements([['insurance']]));
        $compliance->provide('acme', 'ada', 'proof', 'passport');
        $compliance->provide('other', 'ada', 'proof', 'insurance');
        $this->runtime->project();
        $this->runtime->refresh('acme');
        self::assertSame(['ada'], $queries->subjects('acme', 'base'));
        self::assertSame([], $queries->qualifications('other', 'ada'));

        $this->runtime->refresh('other');
        self::assertSame(['ada'], $queries->subjects('other', 'base'));
        $compliance->withdraw('acme', 'ada', 'proof');
        $this->runtime->project();
        self::assertSame(0, $this->runtime->refresh('other'));
        $this->runtime->refresh('acme');
        self::assertSame([], $queries->subjects('acme', 'base'));
        self::assertSame(['ada'], $queries->subjects('other', 'base'));
    }

    public function test_a_failed_invalidation_rolls_back_source_rows_and_retries_the_event(): void
    {
        $compliance = $this->runtime->compliance;
        $compliance->define('acme', 'base', new Requirements([['passport']]));
        $compliance->provide('acme', 'ada', 'p-1', 'passport');
        $this->runtime->project();
        $this->runtime->refresh('acme');
        self::assertSame(['ada'], $this->runtime->queries->subjects('acme', 'base'));

        $this->connection->executeStatement(<<<'SQL'
            CREATE FUNCTION reject_after_source_withdrawal() RETURNS trigger LANGUAGE plpgsql AS $$
            BEGIN
                IF NEW.key = 'subject:ada' AND NOT EXISTS (
                    SELECT 1 FROM compliance_evidence
                    WHERE account_id = 'acme' AND subject_id = 'ada' AND evidence_id = 'p-1'
                ) THEN
                    RAISE check_violation USING MESSAGE = 'Invalidation refused after source withdrawal';
                END IF;
                RETURN NEW;
            END $$
            SQL);
        $this->connection->executeStatement(
            'CREATE TRIGGER reject_ada BEFORE INSERT ON compliance_dirty_keys
             FOR EACH ROW EXECUTE FUNCTION reject_after_source_withdrawal()',
        );
        $compliance->withdraw('acme', 'ada', 'p-1');
        try {
            $this->runtime->project();
            self::fail('The source projection must fail when it cannot mark the subject dirty.');
        } catch (DriverException $exception) {
            self::assertSame('23514', $exception->getSQLState());
        }
        self::assertSame(['ada'], $this->runtime->reference->subjects('acme', 'base'));
        self::assertSame(0, $this->runtime->refresh('acme'));
        $this->connection->executeStatement('DROP TRIGGER reject_ada ON compliance_dirty_keys');

        $restarted = new Runtime($this->connection, subjectBatchSize: 1);
        $restarted->project();
        self::assertSame([], $restarted->reference->subjects('acme', 'base'));
        $restarted->refresh('acme');
        self::assertSame([], $restarted->queries->subjects('acme', 'base'));
    }

    public function test_a_failed_publication_preserves_initial_build_progress_for_retry(): void
    {
        $compliance = $this->runtime->compliance;
        $compliance->provide('acme', 'ada', 'p-1', 'passport');
        $compliance->provide('acme', 'lin', 'p-1', 'passport');
        $this->runtime->project();
        $this->runtime->refresh('acme');
        $compliance->define('acme', 'base', new Requirements([['passport']]));
        $this->runtime->project();
        self::assertSame(1, $this->runtime->refresh('acme', maxSteps: 1));

        $this->connection->executeStatement(
            'ALTER TABLE compliance_qualification_refresh ADD CONSTRAINT reject_readiness CHECK (NOT ready)',
        );
        try {
            $this->runtime->refresh('acme', maxSteps: 1);
            self::fail('The last page must fail when it cannot publish readiness.');
        } catch (DriverException $exception) {
            self::assertSame('23514', $exception->getSQLState());
        }
        $this->assertQualificationBuilding('base');
        self::assertSame(
            ['ada'],
            $this->connection->fetchFirstColumn('SELECT subject_id FROM compliance_eligibility ORDER BY subject_id'),
        );
        $this->connection->executeStatement(
            'ALTER TABLE compliance_qualification_refresh DROP CONSTRAINT reject_readiness',
        );

        $restarted = new Runtime($this->connection, subjectBatchSize: 1);
        self::assertSame(1, $restarted->refresh('acme', maxSteps: 1));
        self::assertSame(['ada', 'lin'], $restarted->queries->subjects('acme', 'base'));
        self::assertSame(0, $restarted->refresh('acme'));
    }

    private function assertQualificationBuilding(string $qualification): void
    {
        try {
            $this->runtime->queries->subjects('acme', $qualification);
            self::fail('An unfinished build must not look like a completed query.');
        } catch (\DomainException $exception) {
            self::assertSame('Qualification is still building.', $exception->getMessage());
        }
    }
}
