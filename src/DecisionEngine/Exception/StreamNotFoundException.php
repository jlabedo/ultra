<?php

declare(strict_types=1);

namespace Ultra\DecisionEngine\Exception;

class StreamNotFoundException extends \RuntimeException
{
    public function __construct(
        string $domainIdentifier,
        string $value,
    ) {
        parent::__construct(sprintf('Stream not found for %s = %s.', $domainIdentifier, $value));
    }
}
