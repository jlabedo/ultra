<?php

declare(strict_types=1);

namespace Ultra\EventBus\Exception;

final class UnknownEvent extends \RuntimeException
{
    public function __construct(string $eventClass)
    {
        parent::__construct(sprintf('Unknown event "%s". Only classes marked with #[Event(name)] may be published.', $eventClass));
    }
}
