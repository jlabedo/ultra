<?php

declare(strict_types=1);

namespace Ultra\MessageHandling_Compiler;

use Symfony\Component\DependencyInjection\ContainerBuilder;
use Ultra\MessageHandling\Attribute\Advice;
use Ultra\MessageHandling\Attribute\After;
use Ultra\MessageHandling\Attribute\AfterReturning;
use Ultra\MessageHandling\Attribute\AfterThrowing;
use Ultra\MessageHandling\Attribute\Around;
use Ultra\MessageHandling\Attribute\Before;

final readonly class AdviceCollector
{
    public function __construct(
        private string $aspectTag,
    ) {}

    /**
     * @return list<AdviceDescriptor>
     */
    public function collect(
        \ReflectionMethod $handlerMethod,
        ContainerBuilder $container,
    ): array {
        /** @var array<class-string<Advice>, AdviceType> $adviceTypes */
        $adviceTypes = [
            Around::class => AdviceType::Around,
            Before::class => AdviceType::Before,
            AfterReturning::class => AdviceType::AfterReturning,
            AfterThrowing::class => AdviceType::AfterThrowing,
            After::class => AdviceType::After,
        ];

        $advices = [];

        foreach (array_keys($container->findTaggedServiceIds($this->aspectTag)) as $aspectServiceId) {
            $definition = $container->getDefinition($aspectServiceId);
            $aspectClass = $definition->getClass() ?? $aspectServiceId;

            foreach (new \ReflectionClass($aspectClass)->getMethods(\ReflectionMethod::IS_PUBLIC) as $aspectMethod) {
                foreach ($adviceTypes as $attributeClass => $adviceType) {
                    foreach ($aspectMethod->getAttributes($attributeClass) as $refAttr) {
                        /** @var Advice $advice */
                        $advice = $refAttr->newInstance();
                        $result = ($advice->matcher)($handlerMethod);
                        if (null === $result) {
                            continue;
                        }

                        $canProvide = AdviceType::Around === $adviceType || AdviceType::Before === $adviceType;
                        if ([] !== $result->provides && ! $canProvide) {
                            throw new \LogicException(sprintf(
                                'Advice "%s::%s" (#[%s]) returned a MatchResult with "provides" set, but only #[Around] and #[Before] advices may provide dep-param slots.',
                                $aspectClass,
                                $aspectMethod->getName(),
                                new \ReflectionClass($attributeClass)->getShortName(),
                            ));
                        }

                        $advices[] = new AdviceDescriptor(
                            $adviceType,
                            $aspectServiceId,
                            $aspectMethod->getName(),
                            $advice->priority,
                            $aspectClass,
                            $result->provides,
                            $result->config,
                        );
                    }
                }
            }
        }

        return $advices;
    }
}
