<?php

declare(strict_types=1);

namespace Ultra\MappedTable\Symfony;

use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Reference;
use Ultra\Container\ContainerExtension;
use Ultra\MappedTable\MappedTableUnitOfWork;
use Ultra\MappedTable\QueryGuardDefinition;
use Ultra\MappedTable\SchemaMappedRowSet;

final readonly class MappedTableExtension implements ContainerExtension
{
    /** @internal Identifies compiled row-set services. */
    public const string ROW_SET_TAG = 'ultra.mapped_table.compiled_row_set';

    /**
     * @param list<QueryGuardDefinition> $queryGuards
     * @param list<SchemaMappedRowSet> $schemaRowSets
     */
    public function __construct(
        private array $queryGuards = [],
        private array $schemaRowSets = [],
    ) {}

    public function build(ContainerBuilder $container): void
    {
        $configured = [];
        if ($container->hasParameter(MappedQueryGuardCompilerPass::CONFIGURED_GUARDS)) {
            /** @var list<QueryGuardDefinition> $configured */
            $configured = $container->getParameter(MappedQueryGuardCompilerPass::CONFIGURED_GUARDS);
        } else {
            // Collect projection ownership before assembling row sets and before StateStore binds them.
            $container->addCompilerPass(new MappedRowSetCompilerPass(), priority: -5);
            $container->addCompilerPass(new MappedQueryGuardCompilerPass(), priority: -30);
        }
        $container->setParameter(MappedQueryGuardCompilerPass::CONFIGURED_GUARDS, [
            ...$configured,
            ...$this->queryGuards,
        ]);
        if ([] !== $this->schemaRowSets) {
            /** @var list<SchemaMappedRowSet> $rowSets */
            $rowSets = $container->hasParameter(MappedRowSetCompilerPass::SCHEMA_ROW_SETS)
                ? $container->getParameter(MappedRowSetCompilerPass::SCHEMA_ROW_SETS)
                : [];
            $container->setParameter(MappedRowSetCompilerPass::SCHEMA_ROW_SETS, [...$rowSets, ...$this->schemaRowSets]);
        }
    }

    /**
     * Register a coordinator populated by the MappedTable compiler and flushed by the caller.
     *
     * @internal For compiler passes that register private persistence.
     * @param non-empty-list<string> $rowSets
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
