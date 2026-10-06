<?php

declare(strict_types=1);

namespace Ultra\Mapper_Bundle;

use Symfony\Component\DependencyInjection\ChildDefinition;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Ultra\Container\ContainerExtension;
use Ultra\Mapper\Attribute\AsValue;
use Ultra\Mapper\GeneratedNormalizerName;
use Ultra\Mapper\RuntimeNormalizerInterface;
use Ultra\Mapper\UltraNormalizer;

final class MapperExtension implements ContainerExtension
{
    public function __construct(private readonly string $generationNamespace = GeneratedNormalizerName::DEFAULT_NAMESPACE) {}

    public function build(ContainerBuilder $container): void
    {

        $container->register(UltraNormalizer::class, UltraNormalizer::class)
            ->setArguments(['%kernel.build_dir%/mapper', $this->generationNamespace])
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

        $container->addCompilerPass(new MapperCompilerPass($buildDir.'/mapper', $this->generationNamespace));
    }
}
