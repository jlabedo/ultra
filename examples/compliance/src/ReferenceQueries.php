<?php

declare(strict_types=1);

namespace ComplianceExample;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;

/** The current-state query used directly and to prepare the materialized result. */
final readonly class ReferenceQueries
{
    private const string SATISFIES_REQUIREMENTS = <<<'SQL'
        NOT EXISTS (
            SELECT 1 FROM jsonb_array_elements(qualification.requirements) AS requirement(alternatives)
            WHERE NOT EXISTS (
                SELECT 1
                FROM compliance_evidence AS evidence
                WHERE evidence.account_id = qualification.account_id
                  AND evidence.subject_id = candidate.subject_id
                  AND evidence.document_type IN (
                      SELECT jsonb_array_elements_text(requirement.alternatives)
                  )
            )
        )
        SQL;

    public function __construct(
        private Connection $connection,
    ) {}

    /** @return list<string> */
    public function qualifications(string $account, string $subject): array
    {
        return $this->connection->fetchFirstColumn(
            'SELECT qualification.qualification_id FROM compliance_qualifications AS qualification
             CROSS JOIN (SELECT CAST(:subject AS TEXT) AS subject_id) AS candidate
             WHERE qualification.account_id = :account AND '
            . self::SATISFIES_REQUIREMENTS
            . '
             ORDER BY qualification.qualification_id',
            ['account' => $account, 'subject' => $subject],
        );
    }

    /** @return list<string> */
    public function subjects(string $account, string $qualification): array
    {
        return $this->connection->fetchFirstColumn(
            'SELECT candidate.subject_id
             FROM compliance_qualifications AS qualification
             JOIN (SELECT DISTINCT account_id, subject_id FROM compliance_evidence) AS candidate
               ON candidate.account_id = qualification.account_id
             WHERE qualification.account_id = :account AND qualification.qualification_id = :qualification
               AND ' . self::SATISFIES_REQUIREMENTS . '
             ORDER BY candidate.subject_id',
            ['account' => $account, 'qualification' => $qualification],
        );
    }

    /**
     * Existing results are also candidates so a rescan can remove them after the last evidence disappears.
     * @return list<array{subject_id: string, eligible: bool, materialized: bool}>
     */
    public function page(string $account, string $qualification, ?string $after, int $limit): array
    {
        $criteria = null === $after ? '' : ' AND subject_id > :after';
        $params = ['account' => $account, 'qualification' => $qualification, 'limit' => $limit];
        if (null !== $after) {
            $params['after'] = $after;
        }
        $matches = self::SATISFIES_REQUIREMENTS;

        return $this->connection->fetchAllAssociative(
            <<<SQL
                WITH candidate AS (
                    (SELECT DISTINCT subject_id FROM compliance_evidence
                     WHERE account_id = :account{$criteria} ORDER BY subject_id LIMIT :limit)
                    UNION
                    (SELECT subject_id FROM compliance_eligibility
                     WHERE account_id = :account AND qualification_id = :qualification{$criteria}
                     ORDER BY subject_id LIMIT :limit)
                    ORDER BY subject_id LIMIT :limit
                )
                SELECT candidate.subject_id,
                       ({$matches}) AS eligible,
                       (existing.subject_id IS NOT NULL) AS materialized
                FROM candidate
                JOIN compliance_qualifications AS qualification
                  ON qualification.account_id = :account AND qualification.qualification_id = :qualification
                LEFT JOIN compliance_eligibility AS existing
                  ON existing.account_id = qualification.account_id
                 AND existing.qualification_id = qualification.qualification_id
                 AND existing.subject_id = candidate.subject_id
                ORDER BY candidate.subject_id
                SQL,
            $params,
            ['limit' => ParameterType::INTEGER],
        );
    }
}
