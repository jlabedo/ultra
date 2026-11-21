<?php

declare(strict_types=1);

namespace ComplianceExample\Refresh;

use ComplianceExample\ReferenceQueries;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;
use Ultra\Coordination\Refresh\RefreshStep;
use Ultra\Coordination\Refresh\RefreshWorker;
use Ultra\Coordination\Tracking\PendingKey;
use Ultra\MappedTable\MappedColumn;
use Ultra\MappedTable\MappedKey;
use Ultra\MappedTable\MappedRow;
use Ultra\MappedTable\MappedRowSet;
use Ultra\MappedTable\MappedRowSetAdapter;
use Ultra\MappedTable\MappedRowSetAdapterFactory;
use Ultra\MappedTable\MappedRowSetMapping;

final readonly class EligibilityRefresh
{
    private MappedRowSetMapping $resultMapping;

    private MappedRowSetAdapter $resultAdapter;

    public function __construct(
        private Connection $connection,
        private ReferenceQueries $reference,
        private RefreshWorker $worker,
        MappedRowSetAdapterFactory $adapters,
    ) {
        $key = ['account_id', 'subject_id', 'qualification_id'];
        $this->resultMapping = new MappedRowSetMapping(
            'compliance_eligibility',
            array_fill_keys($key, new MappedColumn('string')),
            $key,
            ownerColumns: ['account_id', 'subject_id'],
        );
        $this->resultAdapter = $adapters->create($this->resultMapping->table, $this->resultMapping->keyColumns);
    }

    public function run(string $account, int $maxSteps, int $subjectBatchSize): int
    {
        return $this->worker->run(
            Scope::channel($account),
            function (PendingKey $marker) use ($account, $subjectBatchSize): RefreshStep {
                if (str_starts_with($marker->key, Scope::SUBJECT)) {
                    return $this->subject($account, substr($marker->key, strlen(Scope::SUBJECT)), $marker->counter);
                }
                if (str_starts_with($marker->key, Scope::QUALIFICATION)) {
                    $qualification = substr($marker->key, strlen(Scope::QUALIFICATION));

                    return $this->qualification($account, $qualification, $marker->counter, $subjectBatchSize);
                }

                throw new \LogicException('Unknown eligibility refresh scope.');
            },
            $maxSteps,
        );
    }

    private function subject(string $account, string $subject, int $counter): RefreshStep
    {
        $owner = ['account_id' => $account, 'subject_id' => $subject];
        $rows = $this->results();
        $rows->reconcileForOwner($owner, array_map(
            static fn(string $qualification): MappedRow => new MappedRow(new MappedKey([
                ...$owner,
                'qualification_id' => $qualification,
            ]), []),
            $this->reference->qualifications($account, $subject),
        ));

        return new RefreshStep($rows->flush(...), $counter);
    }

    private function qualification(
        string $account,
        string $qualification,
        int $counter,
        int $subjectBatchSize,
    ): RefreshStep {
        $progress = $this->connection->fetchAssociative(
            'SELECT after_subject, observed_counter FROM compliance_qualification_refresh
             WHERE account_id = ? AND qualification_id = ?',
            [$account, $qualification],
        );
        // Keep the count from the first page. An invalidation received partway through needs another pass.
        // Concurrent progress can belong to a newer publication; its revision will fence this step.
        $observed = min($counter, (int) ($progress['observed_counter'] ?? $counter));
        $page = $this->reference->page(
            $account,
            $qualification,
            $progress['after_subject'] ?? null,
            $subjectBatchSize + 1,
        );
        $finished = count($page) <= $subjectBatchSize;
        if (!$finished) {
            array_pop($page);
        }
        $rows = $this->results();
        foreach ($page as $candidate) {
            if ($candidate['eligible'] === $candidate['materialized']) {
                continue;
            }
            $row = $rows->withKey([
                'account_id' => $account,
                'subject_id' => $candidate['subject_id'],
                'qualification_id' => $qualification,
            ]);
            if ($candidate['eligible']) {
                $row->insert();
            } else {
                $row->delete();
            }
        }
        $cursor = $finished ? null : $page[array_key_last($page)]['subject_id'];

        return new RefreshStep(
            function () use ($account, $qualification, $rows, $finished, $cursor, $observed): void {
                $rows->flush();
                $this->connection->executeStatement(
                    'INSERT INTO compliance_qualification_refresh
                    (account_id, qualification_id, ready, after_subject, observed_counter) VALUES (?, ?, ?, ?, ?)
                 ON CONFLICT (account_id, qualification_id) DO UPDATE SET
                    ready = compliance_qualification_refresh.ready OR EXCLUDED.ready,
                    after_subject = EXCLUDED.after_subject, observed_counter = EXCLUDED.observed_counter',
                    [$account, $qualification, $finished, $cursor, $finished ? null : $observed],
                    [2 => ParameterType::BOOLEAN, 4 => ParameterType::INTEGER],
                );
            },
            $finished ? $observed : 0,
        );
    }

    private function results(): MappedRowSet
    {
        return new MappedRowSet($this->resultAdapter, $this->resultMapping);
    }
}
