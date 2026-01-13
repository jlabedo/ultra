<?php

namespace Ultra\Mapper\Tests\Fixtures;

class PromotedAndNonPromoted
{
    public string $nonPromoted;

    public function __construct(
        public string $promoted,
    ) {}
}
