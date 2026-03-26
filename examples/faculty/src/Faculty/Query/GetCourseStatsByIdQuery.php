<?php

declare(strict_types=1);

namespace App\Faculty\Query;

final readonly class GetCourseStatsByIdQuery
{
    public function __construct(
        public string $courseId,
    ) {}
}
