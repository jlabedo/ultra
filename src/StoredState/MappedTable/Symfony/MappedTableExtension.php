<?php

declare(strict_types=1);

namespace Ultra\MappedTable\Symfony;

use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Reference;
use Ultra\Container\ContainerExtension;
use Ultra\MappedTable\MappedRowSetDefinition;
use Ultra\MappedTable\MappedTableUnitOfWork;
use Ultra\MappedTable\QueryGuardDefinition;

final readonly class MappedTableExtension implements ContainerExtension
{
    /** @internal Identifies the compiled row-set service for each definition class. */
    public const string ROW_SET_TAG = 'ultra.mapped_table.compiled_row_set';

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
            // Collect projection ownership before assembling row sets and before StateStore binds them.
            $container->addCompilerPass(new MappedRowSetCompilerPass(), priority: -5);
            $container->addCompilerPass(new MappedQueryGuardCompilerPass(), priority: -30);
        }
        $container->setParameter(MappedQueryGuardCompilerPass::CONFIGURED_GUARDS, [
            ...$configured,
            ...$this->queryGuards,
        ]);
    }

    /**
     * Register a coordinator populated by the MappedTable compiler and flushed by the caller.
     *
     * @internal For compiler passes that register private persistence.
     * @param non-empty-list<class-string> $rowSets
     */
    public static function privateUnitOfWork(ContainerBuilder $container, string $owner, array $rowSets): Reference
    {
        if (!$container->hasParameter(MappedQueryGuardCompilerPass::CONFIGURED_GUARDS)) {
            throw new \LogicException('Projection row sets require MappedTableExtension.');
        }

        $id = MappedTableUnitOfWork::class . '.private.' . $owner;
        $coordinator = $container->register($id, MappedTableUnitOfWork::class);
        $coordinator->addTag(MappedRowSetCompilerPass::PRIVATE_COORDINATOR_TAG, ['rowSets' => $rowSets]);

        return new Reference($id);
    }
}
