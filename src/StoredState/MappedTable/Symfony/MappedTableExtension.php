<?php

declare(strict_types=1);

namespace Ultra\MappedTable\Symfony;

use Symfony\Component\DependencyInjection\ContainerBuilder;
use Ultra\Container\ContainerExtension;
use Ultra\MappedTable\MappedRowSetDefinition;
use Ultra\MappedTable\QueryGuardDefinition;

final readonly class MappedTableExtension implements ContainerExtension
{
    /** @param list<QueryGuardDefinition> $queryGuards */
    public function __construct(
        private array $queryGuards = [],
    ) {}

    public function build(ContainerBuilder $container): void
    {
        $container
            ->registerForAutoconfiguration(MappedRowSetDefinition::class)
            ->addResourceTag(MappedRowSetCompilerPass::DEFINITION_TAG);

        $container->addCompilerPass(new MappedRowSetCompilerPass($this->queryGuards));
    }
}
