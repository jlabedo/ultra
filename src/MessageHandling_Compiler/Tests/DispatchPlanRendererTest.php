<?php

declare(strict_types=1);

namespace Ultra\MessageHandling_Compiler\Tests;

use PHPUnit\Framework\TestCase;
use Ultra\MessageHandling_Compiler\Handler\AdviceDescriptor;
use Ultra\MessageHandling_Compiler\Handler\AdviceLayer;
use Ultra\MessageHandling_Compiler\Handler\AdviceType;
use Ultra\MessageHandling_Compiler\Handler\DispatchPlan;
use Ultra\MessageHandling_Compiler\Handler\DispatchPlanRenderer;

/**
 * @internal
 *
 * @coversNothing
 */
final class DispatchPlanRendererTest extends TestCase
{
    public function test_it_does_not_wrap_results_when_the_plan_has_no_around_advices(): void
    {
        $renderer = new DispatchPlanRenderer();
        $plan = new DispatchPlan(
            serviceId: 'handler.service',
            handlerMethod: 'handle',
            depServiceIds: [],
            layers: [],
            claimedVarNames: [],
            returnsVoid: false,
        );

        $body = (string) $renderer->renderBody($plan);

        self::assertStringNotContainsString('ProceedResult', $body);
        self::assertStringNotContainsString('$__result', $body);
        self::assertStringContainsString("return \$this->locator->get('handler.service')->handle(\$m);", $body);
    }

    public function test_it_keeps_raw_results_for_after_returning_when_the_plan_has_no_around_advices(): void
    {
        $renderer = new DispatchPlanRenderer();
        $plan = new DispatchPlan(
            serviceId: 'handler.service',
            handlerMethod: 'handle',
            depServiceIds: [],
            layers: [
                new AdviceLayer(
                    befores: [],
                    arounds: [],
                    afterReturnings: [
                        new AdviceDescriptor(
                            type: AdviceType::AfterReturning,
                            priority: 0,
                            className: 'AfterAspect',
                            method: 'record',
                            serviceId: 'after.service',
                        ),
                    ],
                    afterThrowings: [],
                    afters: [],
                ),
            ],
            claimedVarNames: [],
            returnsVoid: false,
        );

        $body = (string) $renderer->renderBody($plan);

        self::assertStringNotContainsString('ProceedResult', $body);
        self::assertStringContainsString("\$__result0 = \$this->locator->get('handler.service')->handle(\$m);", $body);
        self::assertStringContainsString("\$this->locator->get('after.service')->record(\$m, \$c, \$__result0);", $body);
    }

    public function test_it_syncs_proceed_result_context_after_patch_returning_after_advices(): void
    {
        $renderer = new DispatchPlanRenderer();
        $plan = new DispatchPlan(
            serviceId: 'handler.service',
            handlerMethod: 'handle',
            depServiceIds: [],
            layers: [
                new AdviceLayer(
                    befores: [],
                    arounds: [
                        new AdviceDescriptor(
                            type: AdviceType::Around,
                            priority: 0,
                            className: 'AroundAspect',
                            method: 'wrap',
                            serviceId: 'around.service',
                        ),
                    ],
                    afterReturnings: [],
                    afterThrowings: [],
                    afters: [
                        new AdviceDescriptor(
                            type: AdviceType::After,
                            priority: 10,
                            className: 'AfterAspect',
                            method: 'enrich',
                            serviceId: 'after.service',
                            returnsContextPatch: true,
                        ),
                    ],
                ),
            ],
            claimedVarNames: [],
            returnsVoid: false,
        );

        $body = (string) $renderer->renderBody($plan);

        self::assertStringContainsString('use ($m, &$c)', $body);
        self::assertStringContainsString("\$__context0_after0 = \$this->locator->get('after.service')->enrich(\$m, \$c);", $body);
        self::assertStringContainsString('$c = $c->merge($__context0_after0);', $body);
        self::assertStringContainsString('$__result0 = $__result0->withContext($c);', $body);
    }
}
