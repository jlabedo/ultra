<?php

declare(strict_types=1);

namespace Ultra\Mediator_Bundle;

use Symfony\Component\DependencyInjection\ChildDefinition;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\HttpKernel\Bundle\Bundle;
use Ultra\Mediator\Attribute\Advice;
use Ultra\Mediator\Attribute\Handles;
use Ultra\Mediator_Compiler\MediatorCompilerPass;

final class MediatorBundle extends Bundle
{
    public function build(ContainerBuilder $container): void
    {
        parent::build($container);

        if (! $container->hasParameter('ultra_mediator.generated_class')) {
            $container->setParameter('ultra_mediator.generated_class', 'CompiledMediator');
        }

        $container->registerAttributeForAutoconfiguration(
            Handles::class,
            static function (ChildDefinition $definition, Handles $attribute, \Reflector $reflector): void {
                if ($reflector instanceof \ReflectionMethod) {
                    $definition->addTag(TagNames::HANDLER);
                }
            }
        );

        $container->registerAttributeForAutoconfiguration(
            Advice::class,
            static function (ChildDefinition $definition, Advice $attribute, \Reflector $reflector): void {
                if ($reflector instanceof \ReflectionMethod) {
                    $definition->addTag(TagNames::ASPECT);
                }
            }
        );

        $buildDir = (string) $container->getParameter('kernel.build_dir');
        $outputDir = $buildDir.'/mediator';
        $generatedClass = (string) $container->getParameter('ultra_mediator.generated_class');

        $container->addCompilerPass(new MediatorCompilerPass(
            $outputDir,
            $generatedClass,
            TagNames::HANDLER,
            TagNames::ASPECT,
            TagNames::SELF_HANDLER,
        ), priority: -100);
    }
}
