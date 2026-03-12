<?php

declare(strict_types=1);

namespace Ultra\Mediator_Example\OrderProcessing\Aspect;

use Ultra\Mediator\Attribute\Before;
use Ultra\Mediator_Example\OrderProcessing\Infrastructure\AuditLog;

final class AuditAspect
{
    public function __construct(private readonly AuditLog $log)
    {
    }

    #[Before(priority: 10)]
    public function record(object $message, array $context): void
    {
        $this->log->record($message, $context);
    }
}
