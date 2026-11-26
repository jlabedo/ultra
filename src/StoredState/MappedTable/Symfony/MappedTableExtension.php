<?php

declare(strict_types=1);

namespace Ultra\MappedTable\Symfony;

use Symfony\Component\DependencyInjection\ChildDefinition;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Ultra\Container\ContainerExtension;
use Ultra\MappedTable\Attribute\DefineMappedRowSet;
use Ultra\MappedTable\ColumnCodec;
use Ultra\MappedTable\MappedRowSetDefinition;
use Ultra\MappedTable\MappedTableUnitOfWork;
use Ultra\MappedTable\QueryGuardDefinition;

final readonly class MappedTableExtension implements ContainerExtension
{
    /**
     * @param list<QueryGuardDefinition> $queryGuards
     * @param array<array-key, MappedRowSetDefinition|string|array{columns?: list<string>, owner?: string, ownerColumns?: list<string>, codecs?: array<string, ColumnCodec|string>}> $rowSets
     */
    public function __construct(
        private array $queryGuards = [],
        private array $rowSets = [],
        private string $unitOfWork = MappedTableUnitOfWork::class,
    ) {}

    public function build(ContainerBuilder $container): void
    {
        $configured = [];
        if ($container->hasParameter(MappedQueryGuardCompilerPass::CONFIGURED_GUARDS)) {
            /** @var list<QueryGuardDefinition> $configured */
            $configured = $container->getParameter(MappedQueryGuardCompilerPass::CONFIGURED_GUARDS);
        } else {
            $container->registerAttributeForAutoconfiguration(DefineMappedRowSet::class, static function (
                ChildDefinition $definition,
                DefineMappedRowSet $attribute,
                \Reflector $method,
            ): void {
                assert($method instanceof \ReflectionMethod, 'DefineMappedRowSet applies to methods.');
                $definition->addTag(MappedRowSetCompilerPass::FACTORY_TAG, [
                    'method' => $method->getName(),
                    'id' => $attribute->id,
                    'unitOfWork' => $attribute->unitOfWork,
                ]);
            });
            $container->addCompilerPass(new MappedRowSetCompilerPass(), priority: -5);
            $container->addCompilerPass(new MappedQueryGuardCompilerPass(), priority: -30);
        }
        $container->setParameter(MappedQueryGuardCompilerPass::CONFIGURED_GUARDS, [
            ...$configured,
            ...$this->queryGuards,
        ]);

        /** @var list<array{string, MappedRowSetDefinition|array{columns?: list<string>, owner?: string, ownerColumns?: list<string>, codecs?: array<string, ColumnCodec|string>}, string}> $selections */
        $selections = $container->hasParameter(MappedRowSetCompilerPass::ROW_SETS)
            ? $container->getParameter(MappedRowSetCompilerPass::ROW_SETS)
            : [];
        foreach ($this->rowSets as $id => $source) {
            if (is_int($id) && is_string($source)) {
                $selections[] = [$source, [], $this->unitOfWork];
            } elseif (is_string($id) && (is_array($source) || $source instanceof MappedRowSetDefinition)) {
                $selections[] = [$id, $source, $this->unitOfWork];
            } else {
                throw new \InvalidArgumentException('Mapped row sets require a table ID or a definition keyed by ID.');
            }
        }
        $container->setParameter(MappedRowSetCompilerPass::ROW_SETS, $selections);
    }
}
