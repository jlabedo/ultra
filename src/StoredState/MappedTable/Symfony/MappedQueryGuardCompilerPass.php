<?php

declare(strict_types=1);

namespace Ultra\MappedTable\Symfony;

use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Definition;
use Symfony\Component\DependencyInjection\Reference;
use Ultra\MappedTable\Attribute\DefineQueryGuard;
use Ultra\MappedTable\GuardLockAdapter;
use Ultra\MappedTable\InMemoryGuardLockAdapter;
use Ultra\MappedTable\InMemoryMappedTableAdapter;
use Ultra\MappedTable\MappedRowSetDefinition;
use Ultra\MappedTable\MappedRowSetMapping;
use Ultra\MappedTable\MappedTableAdapter;
use Ultra\MappedTable\MappedTableUnitOfWork;
use Ultra\MappedTable\PostgresGuardLockAdapter;
use Ultra\MappedTable\PostgresMappedTableAdapter;
use Ultra\MappedTable\QueryGuard;
use Ultra\MappedTable\QueryGuardDefinition;
use Ultra\MappedTable\QueryGuardRule;
use Ultra\MappedTable\QueryGuardRules;

/** @internal */
final class MappedQueryGuardCompilerPass implements CompilerPassInterface
{
    public const string CONFIGURED_GUARDS = 'ultra.mapped_table.compiling.configured_guards';

    public function process(ContainerBuilder $container): void
    {
        /** @var array<class-string<MappedRowSetDefinition>, MappedRowSetMapping> $mappings */
        $mappings = $container->getParameter(MappedRowSetCompilerPass::MAPPINGS);
        $container->getParameterBag()->remove(MappedRowSetCompilerPass::MAPPINGS);
        /** @var array<string, string> $coordinators */
        $coordinators = $container->getParameter(MappedRowSetCompilerPass::COORDINATORS);
        $container->getParameterBag()->remove(MappedRowSetCompilerPass::COORDINATORS);
        /** @var list<QueryGuardDefinition> $configured */
        $configured = $container->getParameter(self::CONFIGURED_GUARDS);
        $container->getParameterBag()->remove(self::CONFIGURED_GUARDS);
        $groups = $this->groupDefinitions([...$configured, ...$this->attributeDefinitions($container)], $coordinators);
        if ([] === $mappings) {
            QueryGuardRules::compile($groups[MappedTableUnitOfWork::class], []);

            return;
        }

        $adapterClass = $container
            ->getParameterBag()
            ->resolveValue($container->findDefinition(MappedTableAdapter::class)->getClass());
        foreach ($groups as $id => $definitions) {
            $rules = QueryGuardRules::compile($definitions, $mappings);
            $this->registerQueryGuards($container, $id, $rules, is_string($adapterClass) ? $adapterClass : null);
        }
    }

    /**
     * @param list<QueryGuardDefinition> $definitions
     * @param array<string, string> $coordinators
     * @return array<string, list<QueryGuardDefinition>>
     */
    private function groupDefinitions(array $definitions, array $coordinators): array
    {
        $groups = array_fill_keys([MappedTableUnitOfWork::class, ...array_values($coordinators)], []);
        $names = [];
        foreach ($definitions as $definition) {
            if (isset($names[$definition->name])) {
                throw new \InvalidArgumentException(
                    'A query guard needs a unique, non-empty name and at least one dependency.',
                );
            }
            $names[$definition->name] = true;

            $group = null;
            foreach ($definition->dependencies as $dependency) {
                $coordinator = $coordinators[$dependency->rowSet] ?? throw new \InvalidArgumentException(sprintf(
                    'Query guard "%s" watches unknown row set definition "%s".',
                    $definition->name,
                    $dependency->rowSet,
                ));
                if (null !== $group && $group !== $coordinator) {
                    throw new \LogicException(sprintf(
                        'Query guard "%s" must watch row sets in the same coordinator.',
                        $definition->name,
                    ));
                }
                $group = $coordinator;
            }
            $groups[$group ?? MappedTableUnitOfWork::class][] = $definition;
        }

        return $groups;
    }

    /** @return list<QueryGuardDefinition> */
    private function attributeDefinitions(ContainerBuilder $container): array
    {
        $guards = [];
        foreach ($container->getDefinitions() as $service) {
            $class = $service->getClass();
            if (null === $class || $service->isAbstract() || !$service->isAutowired()) {
                continue;
            }
            $class = $container->getParameterBag()->resolveValue($class);
            if (!is_string($class) || !class_exists($class)) {
                continue;
            }

            $reflection = $container->getReflectionClass($class, false);
            foreach ($reflection?->getConstructor()?->getParameters() ?? [] as $parameter) {
                $attribute = ($parameter->getAttributes(DefineQueryGuard::class)[0] ?? null)?->newInstance();
                if (null === $attribute) {
                    continue;
                }

                $type = $parameter->getType();
                if (!$type instanceof \ReflectionNamedType || QueryGuard::class !== $type->getName()) {
                    throw new \LogicException(sprintf(
                        '%s::$%s must be typed as %s to use DefineQueryGuard.',
                        $class,
                        $parameter->getName(),
                        QueryGuard::class,
                    ));
                }

                $guards[] = new QueryGuardDefinition($attribute->name, $attribute->dependencies);
                $service->setArgument(
                    '$' . $parameter->getName(),
                    new Reference(QueryGuard::class . '.' . $attribute->name),
                );
            }
        }

        return $guards;
    }

    private function registerQueryGuards(
        ContainerBuilder $container,
        string $coordinator,
        QueryGuardRules $rules,
        ?string $tableAdapterClass,
    ): void {
        $hasAdapter = $container->has(GuardLockAdapter::class);
        if (!$hasAdapter && [] !== $rules->namespaces) {
            throw new \LogicException('Compiled mapped query guards require a GuardLockAdapter.');
        }

        $guardAdapterClass = $hasAdapter ? $container->findDefinition(GuardLockAdapter::class)->getClass() : null;
        $incompatible = match ($tableAdapterClass) {
            InMemoryMappedTableAdapter::class => PostgresGuardLockAdapter::class,
            PostgresMappedTableAdapter::class => InMemoryGuardLockAdapter::class,
            default => null,
        };
        if (null !== $incompatible && $incompatible === $guardAdapterClass) {
            throw new \LogicException('Mapped row sets and query guards must use the same storage backend.');
        }

        $unitOfWork = $container->getDefinition($coordinator);
        $unitOfWork->addArgument(new Definition(QueryGuardRules::class, [
            array_map(
                static fn(QueryGuardRule $rule): Definition => new Definition(QueryGuardRule::class, [
                    $rule->name,
                    $rule->valueColumn,
                    $rule->valueInKey,
                    $rule->valueInOwner,
                    $rule->watchedColumns,
                ]),
                $rules->rules,
            ),
            $rules->byName,
            $rules->byRowSet,
            $rules->namespaces,
        ]));
        if ($hasAdapter) {
            $unitOfWork->addArgument(new Reference(GuardLockAdapter::class));
        }

        foreach (array_keys($rules->namespaces) as $name) {
            $container
                ->register(QueryGuard::class . '.' . $name, QueryGuard::class)
                ->setArguments([new Reference($coordinator), (string) $name]);
        }
    }
}
