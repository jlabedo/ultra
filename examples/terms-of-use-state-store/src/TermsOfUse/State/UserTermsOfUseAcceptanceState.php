<?php

declare(strict_types=1);

namespace App\TermsOfUse\State;

use App\TermsOfUse\TermsOfUse;
use Ultra\StateStore\Attribute\State;
use Ultra\StateStore\Attribute\StateEmbedded;
use Ultra\StateStore\Attribute\StateId;

#[State(table: 'user_terms_of_use_acceptance')]
final readonly class UserTermsOfUseAcceptanceState
{
    public function __construct(
        #[StateId(column: 'user_id')]
        public string $userId,
        #[StateEmbedded(prefix: 'accepted_terms_')]
        public TermsOfUse $acceptedTermsOfUse,
    ) {}

    public function withAcceptedTermsOfUse(TermsOfUse $termsOfUse): self
    {
        return new self($this->userId, $termsOfUse);
    }
}
