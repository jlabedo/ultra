<?php

declare(strict_types=1);

namespace Ultra\DecisionEngine\Tests\Example\Command;

use Ultra\DecisionEngine\Tests\Example\Event\CourseCreated;
use Ultra\DecisionEngine\Tests\Example\State\CourseCapacityState;

readonly class CreateCourseCommand
{
    public function __construct(
        public string $courseId,
        public string $name,
        public int $maxCapacity,
    ) {
    }

    /**
     * @return list<CourseCreated>
     */
    public function decide(
        ?CourseCapacityState $course,
    ): array {
        if ($course !== null) {
            throw new \InvalidArgumentException('Course already exists: ' . $this->courseId);
        }

        return [
            new CourseCreated($this->courseId, $this->name, $this->maxCapacity),
        ];
    }
}
