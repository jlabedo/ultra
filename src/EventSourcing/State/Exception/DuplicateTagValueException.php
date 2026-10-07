<?php

declare(strict_types=1);

namespace Ultra\EventSourcedState\Exception;

class DuplicateTagValueException extends \InvalidArgumentException
{
    public function __construct(string $entityClass, string $tag)
    {
        parent::__construct(sprintf('Duplicate tag value for %s: [%s]', $entityClass, $tag));
    }
}
