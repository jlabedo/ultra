<?php

declare(strict_types=1);

namespace Ultra\MessageHandling_Bundle;

use Symfony\Component\DependencyInjection\ChildDefinition;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\HttpKernel\Bundle\Bundle;
use Ultra\MessageHandling\Attribute\Advice;
use Ultra\MessageHandling\Attribute\MultiDispatch;
use Ultra\MessageHandling\Attribute\SingleDispatch;
use Ultra\MessageHandling_Compiler\MessageRouterCompilerPass;

final class MessageHandlingBundle extends Bundle
{
    public function build(ContainerBuilder $container): void
    {
        parent::build($container);

        // Global aspect autoconfiguration — aspects apply across all routers
        $container->registerAttributeForAutoconfiguration(
            Advice::class,
            static function (ChildDefinition $definition, Advice $attribute, \Reflector $reflector): void {
                if ($reflector instanceof \ReflectionMethod) {
                    $definition->addTag(TagNames::ASPECT);
                }
            }
        );

        $buildDir = (string) $container->getParameter('kernel.build_dir');
        $outputDir = $buildDir.'/message_handling';

        $container->addCompilerPass(new MessageRouterCompilerPass($outputDir));
    }
}
