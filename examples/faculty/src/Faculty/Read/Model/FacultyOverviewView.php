<?php

declare(strict_types=1);

namespace App\Faculty\Read\Model;

use App\Faculty\Support\FacultyIds;

final readonly class FacultyOverviewView implements \JsonSerializable
{
    /**
     * @param list<CourseStatsView>    $courses
     * @param list<StudentProfileView> $students
     */
    public function __construct(
        public array $courses,
        public array $students,
    ) {}

    public function jsonSerialize(): array
    {
        return [
            'facultyId' => FacultyIds::DEFAULT,
            'courses' => $this->courses,
            'students' => $this->students,
        ];
    }
}
