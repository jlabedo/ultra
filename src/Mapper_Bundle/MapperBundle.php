<?php

declare(strict_types=1);

namespace Ultra\Mapper_Bundle;

use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\HttpKernel\Bundle\Bundle;
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

        $container->addCompilerPass(new MapperCompilerPass(), priority: -100);
    }
}
