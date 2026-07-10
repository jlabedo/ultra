<?php

declare(strict_types=1);

namespace App\TermsOfUse\State;

use App\TermsOfUse\TermsOfUse;
use Ultra\StateStore\Attribute\State;
use Ultra\StateStore\Attribute\StateEmbedded;
use Ultra\StateStore\Attribute\StateId;

#[State(table: 'current_terms_of_use', idColumn: 'state_id')]
final readonly class CurrentTermsOfUseState
{
    public function __construct(
        #[StateId]
        public string $currentTermsOfUseStateId,
        #[StateEmbedded(prefix: 'terms_')]
        public TermsOfUse $termsOfUse,
    ) {}

    public function withTermsOfUse(TermsOfUse $termsOfUse): self
    {
        return new self($this->currentTermsOfUseStateId, $termsOfUse);
    }
}
