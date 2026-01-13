<?php

declare(strict_types=1);

namespace Ultra\Mapper;

readonly class Path
{
    /**
     * @param list<int|string|Variable> $segments
     */
    private function __construct(private array $segments) {}

    public static function new(): self
    {
        return new self([]);
    }

    public static function var(string $name): Variable
    {
        return new Variable($name);
    }

    public function add(int|string|Variable ...$segments): self
    {
        return new self([...$this->segments, ...$segments]);
    }

    public function addVariable(string $segmentVariable): self
    {
        return new self([...$this->segments, new Variable($segmentVariable)]);
    }

    public function toArrayAccess(string $prefix = ''): string
    {
        if (empty($this->segments)) {
            return $prefix;
        }

        $path = $prefix.implode('][', $this->toCodeSegments());

        return "[{$path}]";
    }

    /**
     * @param string[] $rawSegments
     */
    public function toArrayString(array $rawSegments = []): string
    {
        return '['.$this->toList($rawSegments).']';
    }

    /**
     * @param string[] $rawSegments
     */
    public function toList(array $rawSegments = []): string
    {
        return implode(', ', [...$this->toCodeSegments(), ...$rawSegments]);
    }

    public function generateRuntimeContextCall(array $rawSegments = []): string
    {
        return "\$context->path->add({$this->toList($rawSegments)})";
    }

    public function __toString(): string
    {
        if (empty($this->segments)) {
            return '';
        }

        $path = implode('][', $this->segments);

        return "[{$path}]";
    }

    /**
     * @return array<int|string>
     */
    private function toCodeSegments(): array
    {
        return array_map(fn ($s) => (is_int($s) || $s instanceof Variable) ? $s : "'{$s}'", $this->segments);
    }
}

/**
 * @internal
 */
class Variable
{
    public function __construct(private string $name) {}

    public function __toString(): string
    {
        return '$'.$this->name;
    }
}
