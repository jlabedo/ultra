<?php

declare(strict_types=1);

namespace Ultra\EventSourcedState;

use Symfony\Component\DependencyInjection\ChildDefinition;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Ultra\Container\ContainerExtension;
use Ultra\EventSourcedState\Attribute\EventSourcedEntity;
use Ultra\EventSourcedState_Compiler\EntityLoaderCompilerPass;

final class EventSourcedStateExtension implements ContainerExtension
{
    public function build(ContainerBuilder $container): void
    {
        $container->registerAttributeForAutoconfiguration(EventSourcedEntity::class, static function (
            ChildDefinition $definition,
            EventSourcedEntity $attribute,
            \Reflector $reflector,
        ): void {
            if ($reflector instanceof \ReflectionClass) {
                $definition->addResourceTag(TagNames::ENTITY);
            }
        });

        $buildDir = $container->getParameter('kernel.build_dir');
        assert(is_string($buildDir), 'The kernel build directory must be a string.');
        $outputDir = $buildDir . '/event_sourced_state';

        $container->addCompilerPass(new EntityLoaderCompilerPass($outputDir, TagNames::ENTITY), priority: 50);
    }
}
