<?php

declare(strict_types=1);

namespace Ultra\Schema\Symfony;

use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Ultra\Schema\SchemaDefinition;

/** @internal */
final class SchemaCompilerPass implements CompilerPassInterface
{
    public const string FRAGMENT_TAG = 'ultra.schema.fragment';

    public function process(ContainerBuilder $container): void
    {
        $fragments = [];
        foreach ($container->findTaggedResourceIds(self::FRAGMENT_TAG) as $tags) {
            foreach ($tags as $tag) {
                /** @var array{class: class-string, method: string} $tag */
                $id = $tag['class'] . '::' . $tag['method'];
                $fragments[$id] ??= $this->fragment($tag['class'], $tag['method']);
            }
        }

        $schema = SchemaDefinition::merge(...array_values($fragments));
        $schema->validateForeignKeys();
        $container->setParameter(SchemaExtension::COMPILED_SCHEMA, [$schema]);
    }

    /** @param class-string $class */
    private function fragment(string $class, string $name): SchemaDefinition
    {
        $method = new \ReflectionMethod($class, $name);
        $return = $method->getReturnType();
        if (
            !$method->isPublic()
            || !$method->isStatic()
            || $method->isAbstract()
            || 0 !== $method->getNumberOfParameters()
            || !$return instanceof \ReflectionNamedType
            || $return->allowsNull()
            || SchemaDefinition::class !== $return->getName()
        ) {
            throw new \LogicException(sprintf(
                'Schema fragment "%s::%s" must be a public static method with no parameters returning %s.',
                $class,
                $name,
                SchemaDefinition::class,
            ));
        }

        $fragment = $method->invoke(null);
        assert($fragment instanceof SchemaDefinition, 'A schema fragment must return its declared type.');

        return $fragment;
    }
}
