<?php

declare(strict_types=1);

namespace App\TermsOfUse\Query;

use App\TermsOfUse\State\CurrentTermsOfUseState;
use App\TermsOfUse\State\UserTermsOfUseAcceptanceState;
use App\TermsOfUse\Support\TermsOfUseIds;
use App\TermsOfUse\TermsOfUseAcceptanceStatus;
use Ultra\EventSourcedState\EventSourcedStateStore;
use Ultra\EventStore\DomainIdentifier;
use Ultra\MessageHandling\Attribute\SelfHandler;

final readonly class GetTermsOfUseAcceptanceStatus
{
    public function __construct(
        public string $userId,
    ) {}

    #[SelfHandler]
    public function handle(EventSourcedStateStore $stateStore): TermsOfUseAcceptanceStatus
    {
        /** @var CurrentTermsOfUseState $terms */
        $terms = $stateStore->load(
            CurrentTermsOfUseState::class,
            new DomainIdentifier('terms', TermsOfUseIds::CURRENT),
        );

        /** @var UserTermsOfUseAcceptanceState $user */
        $user = $stateStore->load(
            UserTermsOfUseAcceptanceState::class,
            new DomainIdentifier('user', $this->userId),
        );

        return new TermsOfUseAcceptanceStatus(
            $this->userId,
            $terms->currentTermsOfUse(),
            $user->acceptedTermsOfUse(),
        );
    }
}
