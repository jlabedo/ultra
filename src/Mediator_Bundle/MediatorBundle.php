<?php

declare(strict_types=1);

namespace Ultra\Mediator_Bundle;

use Symfony\Component\DependencyInjection\ChildDefinition;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Definition;
use Symfony\Component\HttpKernel\Bundle\Bundle;
use Ultra\Mediator\Attribute\Handles;
use Ultra\Mediator\Attribute\SelfHandler;
use Ultra\Mediator\EmptyMediator;
use Ultra\Mediator\MediatorInterface;
use Ultra\Mediator_Compiler\MediatorCompilerPass;
use Ultra\MessageHandling\Attribute\Advice;

final class MediatorBundle extends Bundle
{
    public function build(ContainerBuilder $container): void
    {
        parent::build($container);

        $buildDir = (string) $container->getParameter('kernel.build_dir');

        if (! $container->hasParameter('ultra_mediator.generated_class')) {
            $container->setParameter('ultra_mediator.generated_class', self::defaultGeneratedClass($buildDir));
        }

        $container->registerAttributeForAutoconfiguration(
            Handles::class,
            static function (ChildDefinition $definition, Handles $attribute, \Reflector $reflector): void {
                if ($reflector instanceof \ReflectionMethod) {
                    $definition->addTag(TagNames::HANDLER);
                }
            }
        );

        $container->registerAttributeForAutoconfiguration(
            SelfHandler::class,
            static function (ChildDefinition $definition, SelfHandler $attribute, \Reflector $reflector): void {
                if ($reflector instanceof \ReflectionMethod) {
                    $definition->addTag(TagNames::SELF_HANDLER);
                }
            }
        );

        $container->registerAttributeForAutoconfiguration(
            Advice::class,
            static function (ChildDefinition $definition, Advice $attribute, \Reflector $reflector): void {
                if ($reflector instanceof \ReflectionMethod) {
                    $definition->addTag(TagNames::ASPECT);
                }
            }
        );

        $outputDir = $buildDir.'/mediator';
        $generatedClass = (string) $container->getParameter('ultra_mediator.generated_class');

        $container->setDefinition(MediatorInterface::class, new Definition(EmptyMediator::class));

        if (! class_exists(MediatorCompilerPass::class)) {
            throw new \RuntimeException('Cannot compile the mediator container because ultra/mediator-compiler is not installed.');
        }

        $container->addCompilerPass(new MediatorCompilerPass(
            $outputDir,
            $generatedClass,
            TagNames::HANDLER,
            TagNames::ASPECT,
            TagNames::SELF_HANDLER,
        ));
    }

    private static function defaultGeneratedClass(string $buildDir): string
    {
        return 'CompiledMediator_'.substr(sha1($buildDir), 0, 12);
    }
}
