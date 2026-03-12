<?php

declare(strict_types=1);

namespace Ultra\Mediator_Bundle\DependencyInjection\Compiler;

use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Ultra\Mediator_Bundle\TagNames;
use Ultra\Mediator_Compiler\MediatorCompilerPass;

final class MediatorCompilerPassAdapter implements CompilerPassInterface
{
    public function process(ContainerBuilder $container): void
    {
        $buildDir = (string) $container->getParameter('kernel.build_dir');
        $outputDir = $buildDir . '/mediator';
        $generatedClass = (string) $container->getParameter('ultra_mediator.generated_class');

        (new MediatorCompilerPass(
            $outputDir,
            $generatedClass,
            TagNames::HANDLER,
            TagNames::ASPECT,
            TagNames::SELF_HANDLER,
        ))->process($container);
    }
}
