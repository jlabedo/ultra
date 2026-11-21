<?php

declare(strict_types=1);

namespace Ultra\MappedTable\Symfony;

use Symfony\Component\DependencyInjection\ContainerBuilder;
use Ultra\Container\ContainerExtension;
use Ultra\MappedTable\MappedRowSetDefinition;

final class MappedTableExtension implements ContainerExtension
{
    public function build(ContainerBuilder $container): void
    {
        $container
            ->registerForAutoconfiguration(MappedRowSetDefinition::class)
            ->addResourceTag(MappedRowSetCompilerPass::DEFINITION_TAG);

        $container->addCompilerPass(new MappedRowSetCompilerPass());
    }
}
