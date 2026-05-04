<?php

declare(strict_types=1);

namespace App\Faculty\Query;

use App\Faculty\Read\FacultyReadModelProjection;
use Ultra\MessageHandling\Attribute\SelfHandler;

final readonly class ListCourseStatsQuery
{
    #[SelfHandler]
    public function handle(FacultyReadModelProjection $readModel): array
    {
        return $readModel->courses();
    }
}
