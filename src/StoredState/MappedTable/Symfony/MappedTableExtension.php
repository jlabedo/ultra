<?php

declare(strict_types=1);

namespace Ultra\MappedTable\Symfony;

use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Reference;
use Ultra\Container\ContainerExtension;
use Ultra\MappedTable\MappedTableUnitOfWork;
use Ultra\MappedTable\QueryGuardDefinition;

final readonly class MappedTableExtension implements ContainerExtension
{
    public const string COLUMN_CODEC_TAG = 'ultra.mapped_table.column_codec';

    /** @internal Identifies compiled row-set services. */
    public const string ROW_SET_TAG = 'ultra.mapped_table.compiled_row_set';

    /**
     * @param list<QueryGuardDefinition> $queryGuards
     * @param array<array-key, string|array{columns?: list<string>, owner?: string, ownerColumns?: list<string>}> $rowSets
     */
    public function __construct(
        private array $queryGuards = [],
        private array $rowSets = [],
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
        if ([] !== $this->rowSets) {
            /** @var list<array{string, array{columns?: list<string>, owner?: string, ownerColumns?: list<string>}}> $selections */
            $selections = $container->hasParameter(MappedRowSetCompilerPass::SCHEMA_ROW_SETS)
                ? $container->getParameter(MappedRowSetCompilerPass::SCHEMA_ROW_SETS)
                : [];
            foreach ($this->rowSets as $id => $options) {
                if (is_int($id) && is_string($options)) {
                    $selections[] = [$options, []];
                } elseif (is_string($id) && is_array($options)) {
                    $selections[] = [$id, $options];
                } else {
                    throw new \InvalidArgumentException(
                        'Mapped row sets require a table ID or options keyed by table ID.',
                    );
                }
            }
            $container->setParameter(MappedRowSetCompilerPass::SCHEMA_ROW_SETS, $selections);
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
