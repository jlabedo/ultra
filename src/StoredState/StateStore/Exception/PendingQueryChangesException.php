<?php

declare(strict_types=1);

namespace Ultra\StateStore\Exception;

use Ultra\MappedTable\MappedKey;

/** A decision query would ignore a local write affecting its declared scope. */
final class PendingQueryChangesException extends \LogicException
{
    public function __construct(
        public readonly string $guardName,
        public readonly int|string $guardValue,
        public readonly string $tableKey,
        public readonly MappedKey $key,
    ) {
        parent::__construct(sprintf(
            'Cannot observe query guard "%s" for "%s": pending write on "%s" with key %s affects this scope. Execute the query before preparing affected writes.',
            $guardName,
            $guardValue,
            $tableKey,
            $key->fingerprint(),
        ));
    }
}
