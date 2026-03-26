<?php

declare(strict_types=1);

namespace App\Faculty\Read\Handler;

use App\Faculty\Query\GetCourseStatsByIdQuery;
use App\Faculty\Read\FacultyReadModelBuilder;
use Ultra\Mediator\Attribute\Handles;

final readonly class GetCourseStatsByIdHandler
{
    public function __construct(
        private FacultyReadModelBuilder $builder,
    ) {}

    #[Handles]
    public function handle(GetCourseStatsByIdQuery $query): mixed
    {
        return $this->builder->course($query->courseId);
    }
}
