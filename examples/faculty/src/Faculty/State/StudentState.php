<?php

declare(strict_types=1);

namespace App\Faculty\State;

use App\Faculty\Event\StudentEnrolledInFaculty;
use App\Faculty\Event\StudentSubscribedToCourse;
use App\Faculty\Event\StudentUnsubscribedFromCourse;
use Ultra\DecisionEngine\ReducerTrait;
use Ultra\Events\Attribute\DomainIdentifier;

final class StudentState
{
    use ReducerTrait;

    #[DomainIdentifier('student')]
    private string $studentId = '';

    private bool $enrolled = false;
    private string $firstName = '';
    private string $lastName = '';

    /** @var array<string, true> */
    private array $subscribedCourseIds = [];

    public function firstName(): string
    {
        return $this->firstName;
    }

    public function isEnrolled(): bool
    {
        return $this->enrolled;
    }

    public function isSubscribedTo(string $courseId): bool
    {
        return isset($this->subscribedCourseIds[$courseId]);
    }

    public function lastName(): string
    {
        return $this->lastName;
    }

    public function subscriptionCount(): int
    {
        return count($this->subscribedCourseIds);
    }

    /**
     * @return list<string>
     */
    public function subscribedCourseIds(): array
    {
        $courseIds = array_keys($this->subscribedCourseIds);
        sort($courseIds);

        return $courseIds;
    }

    private function onStudentEnrolledInFaculty(StudentEnrolledInFaculty $event): void
    {
        $this->studentId = $event->studentId;
        $this->firstName = $event->firstName;
        $this->lastName = $event->lastName;
        $this->enrolled = true;
    }

    private function onStudentSubscribedToCourse(StudentSubscribedToCourse $event): void
    {
        $this->subscribedCourseIds[$event->courseId] = true;
    }

    private function onStudentUnsubscribedFromCourse(StudentUnsubscribedFromCourse $event): void
    {
        unset($this->subscribedCourseIds[$event->courseId]);
    }
}
