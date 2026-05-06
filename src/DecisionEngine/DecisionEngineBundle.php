<?php

declare(strict_types=1);

namespace Ultra\DecisionEngine;

use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\HttpKernel\Bundle\Bundle;
use Ultra\DecisionEngine\Aspect\DecisionEngineAspect;
use Ultra\MessageHandling\TagNames as MessageHandlingTagNames;

final class DecisionEngineBundle extends Bundle
{
    public function build(ContainerBuilder $container): void
    {
        parent::build($container);

        $container->register(DecisionEngineAspect::class)
            ->setAutoconfigured(true)
            ->setAutowired(true)
            ->addTag(MessageHandlingTagNames::ASPECT)
        ;
    }
}
