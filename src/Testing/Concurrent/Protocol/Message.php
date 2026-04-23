<?php

declare(strict_types=1);

namespace Ultra\Testing\Concurrent\Protocol;

abstract class Message
{
    abstract public function type(): string;
}
