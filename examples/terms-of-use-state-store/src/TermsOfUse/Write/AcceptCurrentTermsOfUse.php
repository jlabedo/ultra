<?php

declare(strict_types=1);

namespace App\TermsOfUse\Write;

use App\TermsOfUse\State\CurrentTermsOfUseState;
use App\TermsOfUse\State\UserTermsOfUseAcceptanceState;
use App\TermsOfUse\Support\CurrentTermsOfUseStateIds;
use Ultra\MessageHandling\Attribute\SelfHandler;
use Ultra\StatefulMessageHandling\Attribute\DecisionHandler;

final readonly class AcceptCurrentTermsOfUse
{
    public function __construct(
        public string $userId,
        public string $currentTermsOfUseStateId = CurrentTermsOfUseStateIds::CURRENT,
    ) {}

    #[SelfHandler]
    #[DecisionHandler]
    public function decide(
        ?CurrentTermsOfUseState $terms,
        ?UserTermsOfUseAcceptanceState $acceptance,
    ): UserTermsOfUseAcceptanceState
    {
        if (null === $terms) {
            throw new \LogicException('Current terms of use have not been defined.');
        }

        $termsOfUse = $terms->termsOfUse;
        if (null === $acceptance) {
            return new UserTermsOfUseAcceptanceState(
                $this->userId,
                $termsOfUse,
            );
        }

        return $acceptance->withAcceptedTermsOfUse($termsOfUse);
    }
}
