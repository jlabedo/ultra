<?php

declare(strict_types=1);

namespace App\Faculty\Write;

use App\Faculty\Event\CourseCapacityChanged;
use App\Faculty\State\CourseState;
use App\Faculty\Support\FacultyIds;
use Ultra\DecisionEngine\Attribute\DecisionHandler;
use Ultra\DecisionEngine\Exception\InvariantViolation;
use Ultra\DecisionEngine\Violation;
use Ultra\MessageHandling\Attribute\SelfHandler;

final readonly class ChangeCourseCapacityCommand
{
    public function __construct(
        public string $courseId,
        public int $capacity,
    ) {}

    /**
     * @return list<Violation>
     */
    public function check(CourseState $course): array
    {
        return array_values(array_filter([
            ! $course->exists()
                ? new Violation('course.not_found', 'Course does not exist.')
                : null,
            $this->capacity <= 0
                ? new Violation('course.capacity.invalid', 'Course capacity must be greater than zero.')
                : null,
            $this->capacity < $course->subscribedStudents()
                ? new Violation('course.capacity.too_small', 'Course capacity cannot be less than the current number of subscribed students.')
                : null,
        ]));
    }

    /**
     * @return list<CourseCapacityChanged>
     */
    #[SelfHandler]
    #[DecisionHandler]
    public function decide(CourseState $course): array
    {
        $violations = $this->check($course);
        if ([] !== $violations) {
            throw new InvariantViolation($violations);
        }

        return [
            new CourseCapacityChanged(FacultyIds::DEFAULT, $this->courseId, $this->capacity),
        ];
    }
}
