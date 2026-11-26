<?php

declare(strict_types=1);

namespace ComplianceExample;

use ComplianceExample\Event\EvidenceProvided;
use ComplianceExample\Event\EvidenceWithdrawn;
use ComplianceExample\Event\QualificationDefined;
use ComplianceExample\Refresh\Scope;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Ultra\Coordination\Tracking\DirtyKeyTracker;
use Ultra\MappedTable\MappedKey;
use Ultra\MappedTable\MappedRowSet;
use Ultra\Projection\Attribute\Projection;
use Ultra\Projection\Attribute\ProjectionHandler;
use Ultra\Projection\Attribute\ProjectionResetter;
use Ultra\UnitOfWork\UnitOfWork;

#[Projection(name: self::NAME, partitionBy: 'account')]
final class SourceProjection implements UnitOfWork
{
    public const string NAME = 'compliance_sources';

    /** @var array<string, array<string, true>> */
    private array $invalidations = [];

    public function __construct(
        #[Autowire(service: ComplianceSchema::EVIDENCE)]
        private readonly MappedRowSet $evidence,
        #[Autowire(service: ComplianceSchema::QUALIFICATIONS)]
        private readonly MappedRowSet $qualifications,
        private readonly DirtyKeyTracker $dirty,
    ) {}

    #[ProjectionHandler]
    public function provided(EvidenceProvided $event): void
    {
        $key = self::evidenceKey($event->account, $event->subject, $event->evidence);
        $this->evidence->withKey($key)->insert()->setColumn('document_type', $event->documentType);
        $this->invalidations[Scope::channel($event->account)][Scope::subject($event->subject)] = true;
    }

    #[ProjectionHandler]
    public function withdrawn(EvidenceWithdrawn $event): void
    {
        $this->evidence->withKey(self::evidenceKey($event->account, $event->subject, $event->evidence))->delete();
        $this->invalidations[Scope::channel($event->account)][Scope::subject($event->subject)] = true;
    }

    #[ProjectionHandler]
    public function qualificationDefined(QualificationDefined $event): void
    {
        $key = new MappedKey(['account_id' => $event->account, 'qualification_id' => $event->qualification]);
        $this->qualifications->withKey($key)->insert()->setColumn('requirements', $event->requirements);
        $this->invalidations[Scope::channel($event->account)][Scope::qualification($event->qualification)] = true;
    }

    #[ProjectionResetter]
    public function reset(): void
    {
        $this->evidence->reconcileRows([]);
        $this->qualifications->reconcileRows([]);
    }

    public function hasPendingChanges(): bool
    {
        return [] !== $this->invalidations;
    }

    public function flush(): void
    {
        // The ProjectionRunner owns the transaction shared by the source rows, marks and checkpoint.
        foreach ($this->invalidations as $channel => $keys) {
            foreach (array_keys($keys) as $key) {
                $this->dirty->bump($channel, $key);
            }
        }
        $this->invalidations = [];
    }

    public function clear(): void
    {
        $this->invalidations = [];
    }

    private static function evidenceKey(string $account, string $subject, string $evidence): MappedKey
    {
        return new MappedKey(['account_id' => $account, 'subject_id' => $subject, 'evidence_id' => $evidence]);
    }
}
