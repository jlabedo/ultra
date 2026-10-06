<?php

declare(strict_types=1);

namespace Ultra\Testing\Tests\Support;

/** @internal */
final class UnserializableFailure extends \RuntimeException
{
    public function __serialize(): array
    {
        throw new \LogicException('This exception cannot be serialized.');
    }
}
