<?php

declare(strict_types=1);

namespace App\TermsOfUse\Query;

use App\TermsOfUse\State\CurrentTermsOfUseState;
use App\TermsOfUse\State\UserTermsOfUseAcceptanceState;
use App\TermsOfUse\Support\CurrentTermsOfUseStateIds;
use App\TermsOfUse\TermsOfUseAcceptanceStatus;
use Ultra\MessageHandling\Attribute\SelfHandler;
use Ultra\StatefulMessageHandling\Attribute\StateReader;

final readonly class GetTermsOfUseAcceptanceStatus
{
    public function __construct(
        public string $userId,
        public string $currentTermsOfUseStateId = CurrentTermsOfUseStateIds::CURRENT,
    ) {}

    #[SelfHandler]
    #[StateReader]
    public function handle(
        ?CurrentTermsOfUseState $terms,
        ?UserTermsOfUseAcceptanceState $user,
    ): TermsOfUseAcceptanceStatus {
        return new TermsOfUseAcceptanceStatus($this->userId, $terms?->termsOfUse, $user?->acceptedTermsOfUse);
    }
}
