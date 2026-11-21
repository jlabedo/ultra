<?php

declare(strict_types=1);

namespace Ultra\MappedTable\Exception;

final class UnknownQueryGuardException extends \InvalidArgumentException
{
    public function __construct(
        public readonly string $guardName,
    ) {
        parent::__construct(sprintf('Unknown mapped query guard "%s".', $guardName));
    }
}
