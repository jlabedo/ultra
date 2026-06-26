<?php

declare(strict_types=1);

namespace Ultra\Signal;

interface SignalHandler
{
    public function queueName(): string;

    /**
     * @param list<SignalHint> $hints
     */
    public function handleSignalHints(array $hints): void;
}
