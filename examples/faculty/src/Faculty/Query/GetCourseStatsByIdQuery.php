<?php

declare(strict_types=1);

namespace App\Faculty\Query;

use App\Faculty\Read\FacultyReadModelProjection;
use Ultra\MessageHandling\Attribute\SelfHandler;

final readonly class GetCourseStatsByIdQuery
{
    public function __construct(
        public string $courseId,
    ) {}

    #[SelfHandler]
    public function handle(FacultyReadModelProjection $readModel): mixed
    {
        return $readModel->course($this->courseId);
    }
}
