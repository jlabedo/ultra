<?php

declare(strict_types=1);

namespace App\TermsOfUse;

final readonly class TermsOfUseAcceptanceStatus implements \JsonSerializable
{
    public function __construct(
        public string $userId,
        public ?TermsOfUse $currentTermsOfUse,
        public ?TermsOfUse $acceptedTermsOfUse,
    ) {}

    public function mustAcceptCurrentTermsOfUse(): bool
    {
        return null !== $this->currentTermsOfUse && !$this->currentTermsOfUse->equals($this->acceptedTermsOfUse);
    }

    public function jsonSerialize(): array
    {
        return [
            'userId' => $this->userId,
            'currentTermsOfUse' => $this->currentTermsOfUse,
            'acceptedTermsOfUse' => $this->acceptedTermsOfUse,
            'mustAcceptCurrentTermsOfUse' => $this->mustAcceptCurrentTermsOfUse(),
        ];
    }
}
