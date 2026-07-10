<?php

declare(strict_types=1);

namespace App\TermsOfUse\Write;

use App\TermsOfUse\State\CurrentTermsOfUseState;
use App\TermsOfUse\Support\CurrentTermsOfUseStateIds;
use App\TermsOfUse\TermsOfUse;
use Ultra\MessageHandling\Attribute\SelfHandler;
use Ultra\StatefulMessageHandling\Attribute\DecisionHandler;

final readonly class DefineTermsOfUse
{
    public function __construct(
        public string $termsOfUseId,
        public string $url,
        public string $currentTermsOfUseStateId = CurrentTermsOfUseStateIds::CURRENT,
    ) {}

    #[SelfHandler]
    #[DecisionHandler]
    public function decide(?CurrentTermsOfUseState $terms): CurrentTermsOfUseState
    {
        $termsOfUseId = trim($this->termsOfUseId);
        $url = trim($this->url);

        if ('' === $termsOfUseId) {
            throw new \InvalidArgumentException('A terms-of-use identifier is required.');
        }

        if (false === filter_var($url, FILTER_VALIDATE_URL)) {
            throw new \InvalidArgumentException('A valid terms-of-use URL is required.');
        }

        $termsOfUse = new TermsOfUse($termsOfUseId, $url);
        if (null === $terms) {
            return new CurrentTermsOfUseState($this->currentTermsOfUseStateId, $termsOfUse);
        }

        return $terms->withTermsOfUse($termsOfUse);
    }
}
