<?php

declare(strict_types=1);

namespace Ultra\DecisionEngine_Bundle;

use Symfony\Component\DependencyInjection\ChildDefinition;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\HttpKernel\Bundle\Bundle;
use Ultra\DecisionEngine\Attribute\EventSourcedEntity;
use Ultra\DecisionEngine_Compiler\EntityLoaderCompilerPass;

final class DecisionEngineBundle extends Bundle
{
    public function build(ContainerBuilder $container): void
    {
        parent::build($container);

        $container->registerAttributeForAutoconfiguration(
            EventSourcedEntity::class,
            static function (ChildDefinition $definition, EventSourcedEntity $attribute, \Reflector $reflector): void {
                if ($reflector instanceof \ReflectionClass) {
                    $definition->addResourceTag(TagNames::ENTITY);
                }
            }
        );

        $buildDir = (string) $container->getParameter('kernel.build_dir');
        $outputDir = $buildDir.'/decision_engine';

        $container->addCompilerPass(new EntityLoaderCompilerPass(
            $outputDir,
            TagNames::ENTITY,
        ), priority: -95);
    }
}
