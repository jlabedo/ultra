<?php

declare(strict_types=1);

namespace Ultra\CodeGeneration;

final readonly class PhpSnippet
{
    public function __construct(
        private string $code,
    ) {}

    public static function raw(string $code): self
    {
        return new self($code);
    }

    public function indentContinuationLines(int $levels): string
    {
        $lines = explode("\n", $this->code);
        if (1 === count($lines)) {
            return $this->code;
        }

        $prefix = PhpCode::indentLevel($levels);

        return implode("\n", [
            $lines[0],
            ...array_map(
                static fn (string $line): string => '' === $line ? '' : $prefix.$line,
                array_slice($lines, 1),
            ),
        ]);
    }

    public function __toString(): string
    {
        return $this->code;
    }
}
