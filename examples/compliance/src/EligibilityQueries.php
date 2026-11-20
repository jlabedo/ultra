<?php

declare(strict_types=1);

namespace ComplianceExample;

use Doctrine\DBAL\Connection;

final readonly class EligibilityQueries
{
    public function __construct(
        private Connection $connection,
    ) {}

    /** @return list<string> */
    public function subjects(string $account, string $qualification): array
    {
        $rows = $this->connection->fetchAllAssociative(
            'SELECT COALESCE(progress.ready, FALSE) AS ready, eligibility.subject_id
             FROM compliance_qualifications AS qualification
             LEFT JOIN compliance_qualification_refresh AS progress USING (account_id, qualification_id)
             LEFT JOIN compliance_eligibility AS eligibility USING (account_id, qualification_id)
             WHERE qualification.account_id = ? AND qualification.qualification_id = ?
             ORDER BY eligibility.subject_id',
            [$account, $qualification],
        );
        if ([] === $rows) {
            throw new \DomainException('Unknown qualification.');
        }
        if (!$rows[0]['ready']) {
            throw new \DomainException('Qualification is still building.');
        }

        return null === $rows[0]['subject_id'] ? [] : array_column($rows, 'subject_id');
    }

    /** @return list<string> */
    public function qualifications(string $account, string $subject): array
    {
        return $this->connection->fetchFirstColumn(
            'SELECT eligibility.qualification_id FROM compliance_eligibility AS eligibility
             JOIN compliance_qualification_refresh AS progress USING (account_id, qualification_id)
             WHERE eligibility.account_id = ? AND eligibility.subject_id = ? AND progress.ready
             ORDER BY eligibility.qualification_id',
            [$account, $subject],
        );
    }
}
