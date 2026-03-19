<?php

declare(strict_types=1);

namespace Ultra\MessageHandling_Compiler;

interface HandlerDependencyExpressionResolverInterface
{
    public function resolve(string $serviceId, string $contextVar): ?string;
}
