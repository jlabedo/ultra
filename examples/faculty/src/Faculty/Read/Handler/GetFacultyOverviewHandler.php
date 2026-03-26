<?php

declare(strict_types=1);

namespace App\Faculty\Read\Handler;

use App\Faculty\Query\GetFacultyOverviewQuery;
use App\Faculty\Read\FacultyReadModelBuilder;
use Ultra\Mediator\Attribute\Handles;

final readonly class GetFacultyOverviewHandler
{
    public function __construct(
        private FacultyReadModelBuilder $builder,
    ) {}

    #[Handles]
    public function handle(GetFacultyOverviewQuery $query): mixed
    {
        return $this->builder->overview();
    }
}
