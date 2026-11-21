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
        $configured = [];
        if ($container->hasParameter(MappedQueryGuardCompilerPass::CONFIGURED_GUARDS)) {
            /** @var list<QueryGuardDefinition> $configured */
            $configured = $container->getParameter(MappedQueryGuardCompilerPass::CONFIGURED_GUARDS);
        } else {
            $container
                ->registerForAutoconfiguration(MappedRowSetDefinition::class)
                ->addResourceTag(MappedRowSetCompilerPass::DEFINITION_TAG);
            $container->addCompilerPass(new MappedRowSetCompilerPass());
            $container->addCompilerPass(new MappedQueryGuardCompilerPass(), priority: -30);
        }
        $container->setParameter(MappedQueryGuardCompilerPass::CONFIGURED_GUARDS, [
            ...$configured,
            ...$this->queryGuards,
        ]);
    }
}
