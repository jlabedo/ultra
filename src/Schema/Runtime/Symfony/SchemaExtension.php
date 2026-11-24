<?php

declare(strict_types=1);

namespace Ultra\Schema\Symfony;

use Symfony\Component\DependencyInjection\ChildDefinition;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Ultra\Container\ContainerExtension;
use Ultra\Schema\Attribute\DefineSchema;
use Ultra\Schema\Migration\GenerateMigrationCommand;
use Ultra\Schema\Migration\SchemaDefinitionData;
use Ultra\Schema\SchemaDefinition;

final class SchemaExtension implements ContainerExtension
{
    private const string GENERATE_COMMAND = 'ultra:schema:generate';

    /** @internal Removed before Symfony optimizes and dumps the container. */
    public const string COMPILED_SCHEMA = 'ultra.schema.compiling.schema';

    public function build(ContainerBuilder $container): void
    {
        $container->registerAttributeForAutoconfiguration(DefineSchema::class, static function (
            ChildDefinition $definition,
            DefineSchema $attribute,
            \Reflector $reflector,
        ): void {
            assert($reflector instanceof \ReflectionMethod, 'DefineSchema applies to methods.');
            $definition->addResourceTag(SchemaCompilerPass::FRAGMENT_TAG, [
                'class' => $reflector->getDeclaringClass()->getName(),
                'method' => $reflector->getName(),
            ]);
        });

        $container
            ->register(GenerateMigrationCommand::class)
            ->setArgument(0, self::GENERATE_COMMAND)
            ->addTag('console.command', ['command' => self::GENERATE_COMMAND]);

        $container->addCompilerPass(new SchemaCompilerPass());
        $container->addCompilerPass(
            new class implements CompilerPassInterface {
                public function process(ContainerBuilder $container): void
                {
                    $schema = SchemaExtension::compiledSchema($container);
                    $json = json_encode(SchemaDefinitionData::encode($schema), JSON_THROW_ON_ERROR);
                    // Schema expressions can contain literals such as '%name%'.
                    $json = str_replace('%', '%%', $json);
                    $parameter = 'ultra.schema.migrations_directory';
                    $historyDirectory = $container->hasParameter($parameter)
                        ? '%' . $parameter . '%'
                        : 'migrations/schema';
                    $container
                        ->getDefinition(GenerateMigrationCommand::class)
                        ->setArgument(1, $json)
                        ->setArgument(2, $historyDirectory);
                    $container->getParameterBag()->remove(SchemaExtension::COMPILED_SCHEMA);
                }
            },
            priority: -100,
        );
    }

    /** @internal For compiler passes running after schema discovery. */
    public static function compiledSchema(ContainerBuilder $container): SchemaDefinition
    {
        if (!$container->hasParameter(self::COMPILED_SCHEMA)) {
            throw new \LogicException('Register SchemaExtension before consuming the compiled schema.');
        }

        /** @var array{SchemaDefinition} $compiled */
        $compiled = $container->getParameter(self::COMPILED_SCHEMA);

        return $compiled[0];
    }
}
