<?php

declare(strict_types=1);

namespace Ultra\Mapper\Tests\Fixtures;

use Ultra\Mapper\Attribute\AsValue;

#[AsValue]
readonly class EmailList
{
    /**
     * @param Email[] $emails
     */
    public function __construct(
        public array $emails
    ) {}
}
