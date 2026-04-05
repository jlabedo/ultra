<?php

declare(strict_types=1);

namespace Ultra\MessageHandling_Compiler\Handler;

use Ultra\MessageHandling\Attribute\AdvicePhase;
use Ultra\MessageHandling\Attribute\HandlerMatchContext;

final readonly class AdviceCollector
{
    /**
     * @param list<AdviceDefinition> $adviceDefinitions
     */
    public function __construct(private array $adviceDefinitions) {}

    /**
     * @return list<AdviceDescriptor>
     */
    public function collect(
        HandlerMatchContext $context,
    ): array {
        $advices = [];

        foreach ($this->adviceDefinitions as $adviceDefinition) {
            $result = ($adviceDefinition->matcher)($context);
            if (null === $result) {
                continue;
            }

            // Phase filtering:
            // - For sync handlers (Execute phase): both Dispatch and Execute phases match,
            //   since dispatch and execute are unified for sync.
            // - For async handlers: only the specific phase must be in the result's phases.
            if (! $this->matchesPhase($context, $result->phases)) {
                continue;
            }

            $canProvide = AdviceType::Around === $adviceDefinition->type || AdviceType::Before === $adviceDefinition->type;
            if ([] !== $result->provides && ! $canProvide) {
                throw new \LogicException(sprintf(
                    'Advice "%s::%s" (#[%s]) returned a MatchResult with "provides" set, but only #[Around] and #[Before] advices may provide dep-param slots.',
                    $adviceDefinition->className,
                    $adviceDefinition->methodName,
                    $adviceDefinition->type->name,
                ));
            }

            $advices[] = new AdviceDescriptor(
                $adviceDefinition->type,
                $adviceDefinition->priority,
                $adviceDefinition->className,
                $adviceDefinition->methodName,
                $adviceDefinition->serviceId,
                $result->provides,
                $result->config,
            );
        }

        return $advices;
    }

    /**
     * @param list<AdvicePhase> $resultPhases
     */
    private function matchesPhase(HandlerMatchContext $context, array $resultPhases): bool
    {
        $currentPhase = $context->phase();

        if (! $context->isAsync()) {
            // Sync handler: Execute is the only phase, and it represents both
            // dispatch and execute. Accept advices for either phase.
            return [] !== $resultPhases;
        }

        // Async handler: strict phase matching.
        return in_array($currentPhase, $resultPhases, true);
    }
}
