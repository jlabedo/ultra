<?php

declare(strict_types=1);

namespace Ultra\DecisionEngine\Exception;

class UnexpectedTagValueException extends \InvalidArgumentException
{
    /**
     * @param list<string> $expectedTags
     */
    public function __construct(string $entityClass, string $tag, array $expectedTags)
    {
        parent::__construct(sprintf(
            'Unexpected tag value for %s: [%s]; expected [%s]',
            $entityClass,
            $tag,
            implode(', ', $expectedTags),
        ));
    }
}
