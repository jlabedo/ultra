<?php

declare(strict_types=1);

namespace Ultra\StateStore_Bundle;

use Symfony\Component\DependencyInjection\ChildDefinition;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\HttpKernel\Bundle\Bundle;
use Ultra\Mapper_Bundle\TagNames as MapperTagNames;
use Ultra\StateStore\Attribute\State;
use Ultra\StateStore_Bundle\DependencyInjection\Compiler\StateStoreCompilerPass;

final class StateStoreBundle extends Bundle
{
    public function build(ContainerBuilder $container): void
    {
        parent::build($container);

        if (!$container->hasParameter('ultra_state_store.state_classes')) {
            $container->setParameter('ultra_state_store.state_classes', []);
        }

        $container->registerAttributeForAutoconfiguration(
            State::class,
            static function (ChildDefinition $definition, State $attribute, \Reflector $reflector): void {
                if ($reflector instanceof \ReflectionClass) {
                    $definition->addResourceTag(TagNames::STATE, ['class' => $reflector->getName()]);
                    $definition->addResourceTag(MapperTagNames::NORMALIZABLE, ['class' => $reflector->getName()]);
                }
            }
        );

        $container->addCompilerPass(new StateStoreCompilerPass());
    }
}
