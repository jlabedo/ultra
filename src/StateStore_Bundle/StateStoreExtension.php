<?php

declare(strict_types=1);

namespace Ultra\StateStore_Bundle;

use Symfony\Component\DependencyInjection\ChildDefinition;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Reference;
use Ultra\Container\ContainerExtension;
use Ultra\Mapper\UltraNormalizer;
use Ultra\Mapper_Bundle\TagNames as MapperTagNames;
use Ultra\StateStore\Attribute\State;
use Ultra\StateStore\StateMetadataRegistry;
use Ultra\StateStore\StateStore;
use Ultra\StateStore\StateTableRegistry;
use Ultra\StateStore_Bundle\DependencyInjection\Compiler\StateStoreCompilerPass;
use Ultra\StateStore_Bundle\DependencyInjection\Compiler\QueryGuardCompilerPass;

final class StateStoreExtension implements ContainerExtension
{
    public function build(ContainerBuilder $container): void
    {

        $container->registerAttributeForAutoconfiguration(
            State::class,
            static function (ChildDefinition $definition, State $attribute, \Reflector $reflector): void {
                if ($reflector instanceof \ReflectionClass) {
                    $definition->addResourceTag(TagNames::STATE, ['class' => $reflector->getName()]);
                    $definition->addResourceTag(MapperTagNames::NORMALIZABLE, ['class' => $reflector->getName()]);
                }
            }
        );

        $container->register(StateStore::class)->setArguments([
            new Reference(StateMetadataRegistry::class),
            new Reference(StateTableRegistry::class),
            new Reference(UltraNormalizer::class),
        ]);

        $container->addCompilerPass(new StateStoreCompilerPass());
        $container->addCompilerPass(new QueryGuardCompilerPass());
    }
}
