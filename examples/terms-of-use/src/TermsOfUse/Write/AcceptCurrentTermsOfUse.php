<?php

declare(strict_types=1);

namespace App\TermsOfUse\Write;

use App\TermsOfUse\Event\TermsOfUseAccepted;
use App\TermsOfUse\State\CurrentTermsOfUseState;
use App\TermsOfUse\State\UserTermsOfUseAcceptanceState;
use App\TermsOfUse\Support\TermsOfUseIds;
use Ultra\MessageHandling\Attribute\SelfHandler;
use Ultra\StatefulMessageHandling\Attribute\DecisionHandler;

final readonly class AcceptCurrentTermsOfUse
{
    public function __construct(
        public string $userId,
        public string $termsId = TermsOfUseIds::CURRENT,
    ) {}

    /**
     * @return list<TermsOfUseAccepted>
     */
    #[SelfHandler]
    #[DecisionHandler]
    public function decide(CurrentTermsOfUseState $terms, UserTermsOfUseAcceptanceState $user): array
    {
        $currentTermsOfUse = $terms->currentTermsOfUse();
        if (null === $currentTermsOfUse) {
            throw new \LogicException('Current terms of use have not been defined.');
        }

        if ($currentTermsOfUse->equals($user->acceptedTermsOfUse())) {
            return [];
        }

        return [new TermsOfUseAccepted($this->termsId, $this->userId, $currentTermsOfUse->id, $currentTermsOfUse->url)];
    }
}
