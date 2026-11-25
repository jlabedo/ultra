<?php

declare(strict_types=1);

namespace App;

use App\TermsOfUse\Persistence\TermsSchema;
use Symfony\Bundle\FrameworkBundle\Kernel\MicroKernelTrait;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\HttpKernel\Kernel as BaseKernel;
use Ultra\MappedTable\Symfony\MappedTableExtension;

final class Kernel extends BaseKernel
{
    use MicroKernelTrait;

    protected function build(ContainerBuilder $container): void
    {
        parent::build($container);
        new MappedTableExtension(rowSets: TermsSchema::rowSets())->build($container);
    }

    public function getProjectDir(): string
    {
        return dirname(__DIR__);
    }
}
