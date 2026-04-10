<?php

declare(strict_types=1);

namespace Ultra\StateStore\Exception;

final class StateNotFoundException extends \RuntimeException
{
    public function __construct(string $stateClass, string|int $id)
    {
        parent::__construct(sprintf(
            'State "%s" with id "%s" was not found.',
            $stateClass,
            (string) $id,
        ));
    }
}
