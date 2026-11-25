<?php

declare(strict_types=1);

namespace App;

use App\Booking\StateStore\Persistence\BookingSchema;
use App\Booking\StateStoreDirect\Persistence\BookingSchema as DirectBookingSchema;
use Symfony\Bundle\FrameworkBundle\Kernel\MicroKernelTrait;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\HttpKernel\Kernel as BaseKernel;
use Ultra\MappedTable\Symfony\MappedTableExtension;

class Kernel extends BaseKernel
{
    use MicroKernelTrait;

    protected function build(ContainerBuilder $container): void
    {
        parent::build($container);
        new MappedTableExtension(rowSets: [
            ...BookingSchema::rowSets(),
            ...DirectBookingSchema::rowSets(),
        ])->build($container);
    }
}
