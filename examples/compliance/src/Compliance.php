<?php

declare(strict_types=1);

namespace ComplianceExample;

use ComplianceExample\Write\DefineQualification;
use ComplianceExample\Write\ProvideEvidence;
use ComplianceExample\Write\WithdrawEvidence;
use Ultra\MessageHandling\MediatorInterface;

final readonly class Compliance
{
    public function __construct(
        private MediatorInterface $mediator,
    ) {}

    public function define(string $account, string $qualification, Requirements $requirements): void
    {
        $this->mediator->send(new DefineQualification($account, $qualification, $requirements));
    }

    public function provide(string $account, string $subject, string $evidence, string $documentType): void
    {
        $this->mediator->send(new ProvideEvidence($account, $subject, $evidence, $documentType));
    }

    public function withdraw(string $account, string $subject, string $evidence): void
    {
        $this->mediator->send(new WithdrawEvidence($account, $subject, $evidence));
    }
}
