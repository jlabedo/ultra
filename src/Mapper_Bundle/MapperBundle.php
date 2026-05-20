<?php

declare(strict_types=1);

namespace Ultra\Mapper_Bundle;

use Symfony\Component\DependencyInjection\ChildDefinition;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\HttpKernel\Bundle\Bundle;
use Ultra\Mapper\Attribute\AsValue;
use Ultra\Mapper\RuntimeNormalizerInterface;
use Ultra\Mapper\UltraNormalizer;
use Ultra\Mapper_Bundle\DependencyInjection\Compiler\MapperCompilerPass;

final class MapperBundle extends Bundle
{
    public function build(ContainerBuilder $container): void
    {
        parent::build($container);

        $container->register(UltraNormalizer::class, UltraNormalizer::class)
            ->setArguments(['%kernel.build_dir%/mapper'])
        ;

        $container->registerForAutoconfiguration(RuntimeNormalizerInterface::class)
            ->addTag(TagNames::RUNTIME_NORMALIZER)
        ;

        $container->registerAttributeForAutoconfiguration(
            AsValue::class,
            static function (ChildDefinition $definition, AsValue $attribute, \Reflector $reflector): void {
                if ($reflector instanceof \ReflectionClass) {
                    $definition->addResourceTag(TagNames::NORMALIZABLE, ['class' => $reflector->getName()]);
                }
            }
        );

        $buildDir = (string) $container->getParameter('kernel.build_dir');

        $container->addCompilerPass(new MapperCompilerPass($buildDir.'/mapper'));
    }
}
