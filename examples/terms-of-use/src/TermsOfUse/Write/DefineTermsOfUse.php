<?php

declare(strict_types=1);

namespace App\TermsOfUse\Write;

use App\TermsOfUse\Event\TermsOfUseDefined;
use App\TermsOfUse\State\CurrentTermsOfUseState;
use App\TermsOfUse\Support\TermsOfUseIds;
use Ultra\MessageHandling\Attribute\SelfHandler;
use Ultra\StatefulMessageHandling\Attribute\DecisionHandler;

final readonly class DefineTermsOfUse
{
    public function __construct(
        public string $termsOfUseId,
        public string $url,
        public string $termsId = TermsOfUseIds::CURRENT,
    ) {}

    /**
     * @return list<TermsOfUseDefined>
     */
    #[SelfHandler]
    #[DecisionHandler]
    public function decide(CurrentTermsOfUseState $terms): array
    {
        $termsOfUseId = trim($this->termsOfUseId);
        $url = trim($this->url);

        if ('' === $termsOfUseId) {
            throw new \InvalidArgumentException('A terms-of-use identifier is required.');
        }

        if (false === filter_var($url, FILTER_VALIDATE_URL)) {
            throw new \InvalidArgumentException('A valid terms-of-use URL is required.');
        }

        $currentTermsOfUse = $terms->currentTermsOfUse();
        if (null !== $currentTermsOfUse
            && $currentTermsOfUse->id === $termsOfUseId
            && $currentTermsOfUse->url === $url) {
            return [];
        }

        return [new TermsOfUseDefined($this->termsId, $termsOfUseId, $url)];
    }
}
