<?php

declare(strict_types=1);

namespace Ultra\DecisionEngine\Exception;

class MissingTagValueException extends \InvalidArgumentException
{
    /**
     * @param list<string> $missingTags
     */
    public function __construct(string $entityClass, array $missingTags)
    {
        parent::__construct(sprintf(
            'Missing tag values for %s: [%s]',
            $entityClass,
            implode(', ', $missingTags),
        ));
    }
}
