<?php

declare(strict_types=1);

namespace Ultra\MappedTable\Exception;

use Ultra\MappedTable\MappedKey;
use Ultra\MappedTable\MappedRowSetDefinition;

final class PendingQueryChangesException extends \LogicException
{
    /** @param class-string<MappedRowSetDefinition> $rowSet */
    public function __construct(
        public readonly string $guardName,
        public readonly int|string $guardValue,
        public readonly string $rowSet,
        public readonly MappedKey $key,
    ) {
        parent::__construct(sprintf(
            'Observe query guard "%s(%s)" before changing mapped row set "%s", key %s.',
            $guardName,
            $guardValue,
            $rowSet,
            $key,
        ));
    }
}
