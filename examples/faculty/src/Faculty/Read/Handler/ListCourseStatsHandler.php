<?php

declare(strict_types=1);

namespace App\Faculty\Read\Handler;

use App\Faculty\Query\ListCourseStatsQuery;
use App\Faculty\Read\FacultyReadModelBuilder;
use Ultra\Mediator\Attribute\Handles;

final readonly class ListCourseStatsHandler
{
    public function __construct(
        private FacultyReadModelBuilder $builder,
    ) {}

    #[Handles]
    public function handle(ListCourseStatsQuery $query): array
    {
        return $this->builder->courses();
    }
}
