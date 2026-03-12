<?php

declare(strict_types=1);

namespace Ultra\Mediator_Bundle;

use Symfony\Component\DependencyInjection\ChildDefinition;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\HttpKernel\Bundle\Bundle;
use Ultra\Mediator\Attribute\Advice;
use Ultra\Mediator\Attribute\Handles;
use Ultra\Mediator_Bundle\DependencyInjection\Compiler\MediatorCompilerPassAdapter;

final class MediatorBundle extends Bundle
{
    public function build(ContainerBuilder $container): void
    {
        parent::build($container);

        if (!$container->hasParameter('ultra_mediator.generated_class')) {
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

        $container->addCompilerPass(new MediatorCompilerPassAdapter(), priority: -100);
    }
}
