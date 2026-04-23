<?php

declare(strict_types=1);

namespace Ultra\Testing\Concurrent\Protocol;

final class AbortMessage extends Message
{
    public function type(): string
    {
        return 'abort';
    }
}
