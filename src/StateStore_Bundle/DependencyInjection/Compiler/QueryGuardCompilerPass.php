<?php

declare(strict_types=1);

namespace Ultra\StateStore_Bundle\DependencyInjection\Compiler;

use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Definition;
use Symfony\Component\DependencyInjection\Reference;
use Ultra\StateStore\Attribute\DefineQueryGuard;
use Ultra\StateStore\GuardLockAdapter;
use Ultra\StateStore\QueryGuard;
use Ultra\StateStore\QueryGuardDefinition;
use Ultra\StateStore\StateMetadata;
use Ultra\StateStore\StateMetadataRegistry;
use Ultra\StateStore\StateTableRegistry;

/** Prototype: execute declaration builders at compilation; inject guards and retain only plain metadata. */
final class QueryGuardCompilerPass implements CompilerPassInterface
{
    public function process(ContainerBuilder $container): void
    {
        $metadata = $container->getDefinition(StateMetadataRegistry::class)->getArgument(0);
        $guards = [];
        foreach ($container->getDefinitions() as $service) {
            $class = $service->getClass();
            // Constructor injection belongs to autowired services. Avoid loading optional vendor services.
            if (null === $class || $service->isAbstract() || !$service->isAutowired()) {
                continue;
            }
            $class = $container->getParameterBag()->resolveValue($class);
            // Other compiler passes may generate these classes later. Do not cache a missing reflection.
            if (!class_exists($class)) {
                continue;
            }
            $reflection = $container->getReflectionClass($class, false);
            if (!$reflection instanceof \ReflectionClass) {
                continue;
            }
            $constructor = $reflection->getConstructor();
            foreach ($constructor?->getParameters() ?? [] as $parameter) {
                $attribute = ($parameter->getAttributes(DefineQueryGuard::class)[0] ?? null)?->newInstance();
                if (null === $attribute) {
                    continue;
                }
                if ('' === $attribute->name || QueryGuard::class !== ($parameter->getType() instanceof \ReflectionNamedType ? $parameter->getType()->getName() : null)) {
                    throw new \LogicException('DefineQueryGuard needs a non-empty name and a QueryGuard parameter.');
                }
                $builder = new QueryGuardDefinition();
                ($attribute->configure)($builder);
                if ([] === $builder->scopes) {
                    throw new \LogicException('Query guard "'.$attribute->name.'" has no watched states.');
                }
                $scopes = [];
                foreach ($builder->scopes as $scope) {
                    $state = isset($metadata[$scope->stateClass]) ? StateMetadata::fromArray($metadata[$scope->stateClass]) : null;
                    if (null === $state) {
                        throw new \LogicException('Query guard "'.$attribute->name.'" watches an unregistered state: '.$scope->stateClass);
                    }
                    $known = [...$state->keyColumns(), ...$state->selectedColumns()];
                    foreach ([$scope->keyBy, ...($scope->columns ?? [])] as $column) {
                        if (!in_array($column, $known, true)) {
                            throw new \LogicException(sprintf('Unknown column "%s" on state "%s" for query guard "%s".', $column, $scope->stateClass, $attribute->name));
                        }
                    }
                    $scopes[] = ['tableKey' => $state->tableKey, 'keyBy' => $scope->keyBy, 'columns' => $scope->columns];
                }
                usort($scopes, static fn (array $left, array $right): int => $left <=> $right);
                if (isset($guards[$attribute->name]) && $guards[$attribute->name] !== $scopes) {
                    throw new \LogicException('Conflicting definitions for query guard "'.$attribute->name.'".');
                }
                $guards[$attribute->name] = $scopes;
                $id = 'ultra.state_store.query_guard.'.hash('sha256', $attribute->name);
                $container->setDefinition($id, new Definition(QueryGuard::class, [
                    new Reference(StateTableRegistry::class), $attribute->name,
                ]));
                $service->setArgument('$'.$parameter->getName(), new Reference($id));
            }
        }
        if ([] !== $guards && !$container->has(GuardLockAdapter::class)) {
            throw new \LogicException('Compiled query guards require a GuardLockAdapter using the flush transaction.');
        }
        ksort($guards, SORT_STRING);
        $tables = $container->getDefinition(StateTableRegistry::class);
        if (!array_key_exists(1, $tables->getArguments())) {
            $tables->setArgument(1, null);
        }
        $tables->setArgument(2, $guards);
    }
}
