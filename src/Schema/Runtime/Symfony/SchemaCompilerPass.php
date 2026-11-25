<?php

declare(strict_types=1);

namespace Ultra\Schema\Symfony;

use Symfony\Component\DependencyInjection\Argument\ServiceClosureArgument;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Reference;
use Ultra\Schema\Migration\GenerateMigrationCommand;
use Ultra\Schema\SchemaDefinition;

/** @internal */
final class SchemaCompilerPass implements CompilerPassInterface
{
    public const string FRAGMENT_TAG = 'ultra.schema.fragment';

    public function process(ContainerBuilder $container): void
    {
        $staticFragments = [];
        $providers = [];
        foreach ($container->findTaggedServiceIds(self::FRAGMENT_TAG, throwOnAbstract: true) as $id => $tags) {
            $definition = $container->getDefinition($id);
            $class = $container->getParameterBag()->resolveValue($definition->getClass());
            $reflection = is_string($class) ? $container->getReflectionClass($class) : null;
            $methods = [];
            foreach ($tags as $tag) {
                $name = $tag['method'] ?? null;
                if (!is_string($name) || null === $reflection || !$reflection->hasMethod($name)) {
                    throw new \LogicException(sprintf(
                        'Service "%s" must tag an existing schema fragment method.',
                        $id,
                    ));
                }

                $method = $reflection->getMethod($name);
                self::validateMethod($method);
                if ($method->isStatic()) {
                    $fragmentId = $method->getDeclaringClass()->getName() . '::' . $method->getName();
                    $staticFragments[$fragmentId] ??= self::staticFragment($method);
                } else {
                    $methods[$method->getName()] = true;
                }
            }

            if ([] === $methods) {
                continue;
            }

            $providers[] = [
                'service' => new ServiceClosureArgument(new Reference($id)),
                'methods' => array_keys($methods),
            ];
        }

        $schema = SchemaDefinition::merge(...array_values($staticFragments));
        $schema->validateForeignKeys();
        $container->setParameter(SchemaExtension::COMPILED_SCHEMA, [$schema]);
        $container->getDefinition(GenerateMigrationCommand::class)->setArgument(3, $providers);
    }

    private static function validateMethod(\ReflectionMethod $method): void
    {
        $return = $method->getReturnType();
        if (
            !$method->isPublic()
            || $method->isAbstract()
            || 0 !== $method->getNumberOfParameters()
            || !$return instanceof \ReflectionNamedType
            || $return->allowsNull()
            || SchemaDefinition::class !== $return->getName()
        ) {
            throw new \LogicException(sprintf(
                'Schema fragment "%s::%s" must be a public method with no parameters returning %s.',
                $method->getDeclaringClass()->getName(),
                $method->getName(),
                SchemaDefinition::class,
            ));
        }
    }

    private static function staticFragment(\ReflectionMethod $method): SchemaDefinition
    {
        $fragment = $method->invoke(null);
        assert($fragment instanceof SchemaDefinition, 'A schema fragment must return its declared type.');

        return $fragment;
    }
}
