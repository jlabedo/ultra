<?php

declare(strict_types=1);

namespace Ultra\MessageHandling_Compiler;

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
        \ReflectionMethod $handlerMethod,
    ): array {
        $advices = [];

        foreach ($this->adviceDefinitions as $adviceDefinition) {
            $result = ($adviceDefinition->matcher)($handlerMethod);
            if (null === $result) {
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
}
