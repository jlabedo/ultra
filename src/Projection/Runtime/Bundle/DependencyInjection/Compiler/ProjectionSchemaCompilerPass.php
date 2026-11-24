<?php

declare(strict_types=1);

namespace Ultra\Projection\Bundle\DependencyInjection\Compiler;

use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Ultra\Projection\Bundle\ProjectionImplementationInterface;
use Ultra\Projection_Postgres\PostgresProjectionImplementation;
use Ultra\Projection_Postgres\PostgresProjectionSchema;
use Ultra\Schema\Migration\GenerateMigrationCommand;

/** @internal */
final class ProjectionSchemaCompilerPass implements CompilerPassInterface
{
    public const string PROVIDER = 'ultra.projection.postgres.schema';

    public function process(ContainerBuilder $container): void
    {
        if (
            !$container->hasDefinition(GenerateMigrationCommand::class)
            || !$container->has(ProjectionImplementationInterface::class)
        ) {
            return;
        }

        $implementation = $container->findDefinition(ProjectionImplementationInterface::class);
        $class = $container->getParameterBag()->resolveValue($implementation->getClass());
        if (
            PostgresProjectionImplementation::class !== $class
            || null !== $implementation->getFactory()
            || $implementation->isSynthetic()
        ) {
            return;
        }

        $container->register(self::PROVIDER, PostgresProjectionSchema::class)->addTag('ultra.schema.fragment', [
            'method' => 'definition',
        ]);
    }
}
