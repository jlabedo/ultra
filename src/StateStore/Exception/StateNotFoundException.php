<?php

declare(strict_types=1);

namespace Ultra\StateStore\Exception;

final class StateNotFoundException extends \RuntimeException
{
    public function __construct(string $stateClass, int|string|object $id)
    {
        parent::__construct(sprintf(
            'State "%s" with id "%s" was not found.',
            $stateClass,
            $id instanceof \Stringable ? (string) $id : (is_object($id) ? $id::class : (string) $id),
        ));
    }
}
