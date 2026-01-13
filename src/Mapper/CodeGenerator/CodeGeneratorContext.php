<?php

namespace Ultra\Mapper\CodeGenerator;

use Ultra\Mapper\Path;

readonly class CodeGeneratorContext
{
    public function __construct(
        public bool $isRoot,
        public bool $inline,
        public Path $path,
        public bool $typeCheck,
        private array $attributes = [],
    ) {}

    public static function new(bool $inline = true, bool $typeCheck = true): self
    {
        return new self(
            isRoot: true,
            inline: $inline,
            path: Path::new(),
            typeCheck: $typeCheck,
        );
    }

    public function with(
        ?bool $isRoot = null,
        ?bool $inline = null,
        ?array $runtimeNormalizers = null,
        ?Path $path = null,
    ): self {
        return new self(
            isRoot: $isRoot ?? $this->isRoot,
            inline: $inline ?? $this->inline,
            path: $path ?? $this->path,
            typeCheck: $this->typeCheck,
            attributes: $this->attributes,
        );
    }

    public function withAttribute(string $namespace, string $name, mixed $value): self
    {
        $namespaceAttributes = $this->attributes[$namespace] ?? [];
        $namespaceAttributes[$name] = $value;

        return new self(
            isRoot: $this->isRoot,
            inline: $this->inline,
            path: $this->path,
            typeCheck: $this->typeCheck,
            attributes: array_merge($this->attributes, [$namespace => $namespaceAttributes]),
        );
    }

    public function getAttribute(string $namespace, string $name): mixed
    {
        return $this->attributes[$namespace][$name] ?? null;
    }

    public function withAddedPathSegment(int|string $segment): self
    {
        return $this->with(path: $this->path->add($segment));
    }
}
