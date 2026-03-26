<?php

declare(strict_types=1);

namespace App\Faculty\Read\Handler;

use App\Faculty\Query\GetStudentProfileByIdQuery;
use App\Faculty\Read\FacultyReadModelBuilder;
use Ultra\Mediator\Attribute\Handles;

final readonly class GetStudentProfileByIdHandler
{
    public function __construct(
        private FacultyReadModelBuilder $builder,
    ) {}

    #[Handles]
    public function handle(GetStudentProfileByIdQuery $query): mixed
    {
        return $this->builder->student($query->studentId);
    }
}
