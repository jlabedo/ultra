<?php

declare(strict_types=1);

namespace GettingStarted;

use Symfony\Bundle\FrameworkBundle\FrameworkBundle;
use Symfony\Bundle\FrameworkBundle\Kernel\MicroKernelTrait;
use Symfony\Component\HttpKernel\Kernel as BaseKernel;
use Ultra\Mapper_Bundle\MapperExtension;
use Ultra\StateStore_Bundle\StateStoreExtension;
use Ultra\SymfonyBundle\UltraBundle;
use Ultra\UnitOfWork\UnitOfWorkExtension;

final class Kernel extends BaseKernel
{
    use MicroKernelTrait;

    public function getProjectDir(): string
    {
        return dirname(__DIR__);
    }

    public function registerBundles(): iterable
    {
        yield new FrameworkBundle();
        yield new UltraBundle([
            new MapperExtension('GettingStartedGenerated'),
            new StateStoreExtension(),
            new UnitOfWorkExtension(),
        ]);
    }
}
