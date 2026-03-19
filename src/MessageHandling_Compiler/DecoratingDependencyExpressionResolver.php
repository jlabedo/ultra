<?php

declare(strict_types=1);

namespace Ultra\MessageHandling_Compiler;

final readonly class DecoratingDependencyExpressionResolver implements HandlerDependencyExpressionResolverInterface
{
    public function __construct(
        private string $serviceId,
        private string $decoratorClass,
        private array $extraArgumentExpressions = [],
    ) {}

    public function resolve(string $serviceId, string $contextVar): ?string
    {
        if ($serviceId !== $this->serviceId) {
            return null;
        }

        return sprintf(
            'new \\%s(%s)',
            ltrim($this->decoratorClass, '\\'),
            implode(', ', [
                '$this->locator->get('.var_export($serviceId, true).')',
                $contextVar,
                ...$this->extraArgumentExpressions,
            ]),
        );
    }
}
