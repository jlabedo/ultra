<?php

declare(strict_types=1);

abstract class StackIncrementer
{
    public function __construct(protected readonly Stack $stack) {}
}
