<?php

namespace Ultra\Mapper;

class DenormalizationContext
{
    public readonly Path $path;

    public function __construct(?Path $path = null)
    {
        $this->path = $path ?? Path::new();
    }

    public function with(
        ?Path $path = null,
    ): self {
        return new self(
            path: $path ?? $this->path,
        );
    }
}
