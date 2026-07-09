<?php

declare(strict_types=1);

namespace App\TermsOfUse;

final readonly class TermsOfUse implements \JsonSerializable
{
    public function __construct(
        public string $id,
        public string $url,
    ) {}

    public function jsonSerialize(): array
    {
        return [
            'id' => $this->id,
            'url' => $this->url,
        ];
    }

    public function equals(?self $other): bool
    {
        return null !== $other
            && $this->id === $other->id
            && $this->url === $other->url;
    }
}
