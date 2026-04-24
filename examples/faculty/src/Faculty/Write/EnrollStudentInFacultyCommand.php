<?php

declare(strict_types=1);

namespace App\Faculty\Write;

use App\Faculty\Event\StudentEnrolledInFaculty;
use App\Faculty\State\StudentState;
use App\Faculty\Support\FacultyIds;
use Ultra\DecisionEngine\Attribute\DecisionHandler;
use Ultra\MessageHandling\Attribute\SelfHandler;

final readonly class EnrollStudentInFacultyCommand
{
    public function __construct(
        public string $studentId,
        public string $firstName,
        public string $lastName,
    ) {}

    /**
     * @return list<StudentEnrolledInFaculty>
     */
    #[SelfHandler]
    #[DecisionHandler]
    public function decide(StudentState $student): array
    {
        if ($student->isEnrolled()) {
            throw new \InvalidArgumentException('Student already enrolled: '.$this->studentId);
        }

        if ('' === trim($this->firstName) || '' === trim($this->lastName)) {
            throw new \InvalidArgumentException('Student first and last name are required.');
        }

        return [
            new StudentEnrolledInFaculty(
                FacultyIds::DEFAULT,
                $this->studentId,
                trim($this->firstName),
                trim($this->lastName),
            ),
        ];
    }
}
