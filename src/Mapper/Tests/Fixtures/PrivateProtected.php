<?php

declare(strict_types=1);

namespace Ultra\Mapper\Tests\Fixtures;

readonly class PrivateProtected
{
    private string $privateName;
    protected int $protectedAge;
    public float $publicScore;
    private bool $privateActive;
    protected Address $protectedAddress;
    public array $publicTags;
    private \DateTimeImmutable $privateCreatedAt;

    public function __construct(
        string $privateName,
        int $protectedAge,
        float $publicScore,
        bool $privateActive,
        Address $protectedAddress,
        array $publicTags,
        \DateTimeImmutable $privateCreatedAt,
    ) {
        $this->privateName = $privateName;
        $this->protectedAge = $protectedAge;
        $this->publicScore = $publicScore;
        $this->privateActive = $privateActive;
        $this->protectedAddress = $protectedAddress;
        $this->publicTags = $publicTags;
        $this->privateCreatedAt = $privateCreatedAt;
    }

    // Getters for testing
    public function getPrivateName(): string
    {
        return $this->privateName;
    }

    public function getProtectedAge(): int
    {
        return $this->protectedAge;
    }

    public function getPrivateActive(): bool
    {
        return $this->privateActive;
    }

    public function getProtectedAddress(): Address
    {
        return $this->protectedAddress;
    }

    public function getPrivateCreatedAt(): \DateTimeImmutable
    {
        return $this->privateCreatedAt;
    }
}
