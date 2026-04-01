<?php

declare(strict_types=1);

namespace Ultra\DecisionEngine\Tests\Example\Command;

use Ultra\DecisionEngine\Attribute\DecisionHandler;
use Ultra\DecisionEngine\Tests\Example\Event\CourseCreated;
use Ultra\DecisionEngine\Tests\Example\State\CourseCapacityState;
use Ultra\Mediator\Attribute\SelfHandler;

readonly class CreateCourseCommand
{
    public function __construct(
        public string $courseId,
        public string $name,
        public int $maxCapacity,
    ) {}

    /**
     * @return list<CourseCreated>
     */
    #[SelfHandler]
    #[DecisionHandler]
    public function decide(
        CourseCapacityState $course,
    ): array {
        if ($course->exists()) {
            throw new \InvalidArgumentException('Course already exists: '.$this->courseId);
        }

        return [
            new CourseCreated($this->courseId, $this->name, $this->maxCapacity),
        ];
    }
}
