<?php

declare(strict_types=1);

namespace Ultra\Schema;

final readonly class PostgresFunctionDefinition
{
    /** @param array<string, string> $arguments Argument names and SQL types in call order. */
    public function __construct(
        public string $id,
        public string $name,
        public array $arguments,
        public string $returnType,
        public string $language,
        public string $body,
    ) {}

    public function equivalentTo(self $other): bool
    {
        return $this->hasSameSignature($other) && $this->language === $other->language && $this->body === $other->body;
    }

    public function hasSameSignature(self $other): bool
    {
        return (
            $this->name === $other->name
            && $this->arguments === $other->arguments
            && $this->returnType === $other->returnType
        );
    }
}
