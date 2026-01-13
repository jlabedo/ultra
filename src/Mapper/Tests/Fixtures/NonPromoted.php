<?php

declare(strict_types=1);

namespace Ultra\Mapper\Tests\Fixtures;

readonly class NonPromoted
{
    public string $publicName;
    public int $publicAge;
    public float $publicScore;
    public bool $publicActive;
    public Address $publicAddress;
    public array $publicTags;
    public \DateTimeImmutable $publicCreatedAt;

    public function __construct(
        private string $name,
        private int $age,
        private float $score,
        private bool $active,
        private Address $address,
        private array $tags,
        private \DateTimeImmutable $createdAt,
    ) {
        // Set public properties to the same values as constructor parameters
        // This allows the current denormalizer implementation to work
        $this->publicName = $name;
        $this->publicAge = $age;
        $this->publicScore = $score;
        $this->publicActive = $active;
        $this->publicAddress = $address;
        $this->publicTags = $tags;
        $this->publicCreatedAt = $createdAt;
    }

    // Getters for private properties
    public function getName(): string
    {
        return $this->name;
    }

    public function getAge(): int
    {
        return $this->age;
    }

    public function getScore(): float
    {
        return $this->score;
    }

    public function getActive(): bool
    {
        return $this->active;
    }

    public function getAddress(): Address
    {
        return $this->address;
    }

    public function getTags(): array
    {
        return $this->tags;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }
}
