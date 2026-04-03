<?php

declare(strict_types=1);

namespace Ultra\MessageHandling_Compiler\Handler;

final class HandlerMethodGenerator
{
    public function __construct(
        private readonly DispatchPlanBuilder $planBuilder = new DispatchPlanBuilder(),
        private readonly DispatchPlanRenderer $renderer = new DispatchPlanRenderer(),
    ) {}

    public function generate(string $methodName, HandlerDescriptor $handler): string
    {
        $plan = $this->planBuilder->build(
            $handler->serviceId,
            $handler->method,
            $handler->depServiceIds,
            $handler->advices,
            $handler->returnsVoid,
        );

        return $this->renderer->renderDispatchMethod($methodName, $plan);
    }
}
