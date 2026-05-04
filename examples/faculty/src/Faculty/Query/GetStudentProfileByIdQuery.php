<?php

declare(strict_types=1);

namespace App\Faculty\Query;

use App\Faculty\Read\FacultyReadModelProjection;
use Ultra\MessageHandling\Attribute\SelfHandler;

final readonly class GetStudentProfileByIdQuery
{
    public function __construct(
        public string $studentId,
    ) {}

    #[SelfHandler]
    public function handle(FacultyReadModelProjection $readModel): mixed
    {
        return $readModel->student($this->studentId);
    }
}
