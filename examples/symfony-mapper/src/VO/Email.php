<?php

namespace App\VO;

use Ultra\Mapper\Attribute\AsValue;

#[AsValue]
readonly class Email
{
    public function __construct(public string $value) {}
}
