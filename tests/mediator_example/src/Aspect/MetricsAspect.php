<?php

declare(strict_types=1);

namespace Ultra\Mediator_Example\OrderProcessing\Aspect;

use Ultra\Mediator\Attribute\AfterReturning;
use Ultra\Mediator\Attribute\AfterThrowing;
use Ultra\Mediator_Example\OrderProcessing\Infrastructure\Metrics;

final class MetricsAspect
{
    public function __construct(private readonly Metrics $metrics)
    {
    }

    #[AfterReturning(priority: 10)]
    public function success(object $message, array $context, mixed $result): void
    {
        $this->metrics->recordSuccess();
    }

    #[AfterThrowing(priority: 10)]
    public function failure(object $message, array $context, \Throwable $e): void
    {
        $this->metrics->recordFailure();
    }
}
