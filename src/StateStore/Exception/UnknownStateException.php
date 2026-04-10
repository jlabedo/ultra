<?php

declare(strict_types=1);

namespace Ultra\StateStore\Exception;

final class UnknownStateException extends \InvalidArgumentException
{
    public function __construct(string $stateClass)
    {
        parent::__construct(sprintf(
            'No mapped state was registered for "%s".',
            $stateClass,
        ));
    }
}
