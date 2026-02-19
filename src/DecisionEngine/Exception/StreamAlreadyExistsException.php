<?php

declare(strict_types=1);

namespace Ultra\DecisionEngine\Exception;

class StreamAlreadyExistsException extends \RuntimeException
{
    public function __construct(
        string $domainIdentifier,
        string $value,
    ) {
parent::__construct(sprintf('Stream already exists for %s = %s.', $domainIdentifier, $value));
    }
}