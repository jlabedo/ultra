<?php

declare(strict_types=1);

namespace App\Faculty\Write\CreateCourse;

use App\Faculty\Event\CourseCreated;
use App\Faculty\State\CourseState;
use App\Faculty\Support\FacultyIds;
use Ultra\DecisionEngine\Attribute\DecisionHandler;
use Ultra\Mediator\Attribute\Handles;

final readonly class CreateCourseCommand
{
    public function __construct(
        public string $courseId,
        public string $name,
        public int $capacity,
    ) {}

    /**
     * @return list<CourseCreated>
     */
    #[Handles]
    #[DecisionHandler]
    public function decide(CourseState $course): array
    {
        if ($course->exists()) {
            throw new \InvalidArgumentException('Course already exists: '.$this->courseId);
        }

        if ('' === trim($this->name)) {
            throw new \InvalidArgumentException('Course name is required.');
        }

        if ($this->capacity <= 0) {
            throw new \InvalidArgumentException('Course capacity must be greater than zero.');
        }

        return [
            new CourseCreated(
                FacultyIds::DEFAULT,
                $this->courseId,
                trim($this->name),
                $this->capacity,
            ),
        ];
    }
}
