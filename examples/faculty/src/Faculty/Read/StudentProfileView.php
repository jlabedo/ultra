<?php

declare(strict_types=1);

namespace App\Faculty\Read;

final readonly class StudentProfileView implements \JsonSerializable
{
    /**
     * @param list<string> $subscribedCourseIds
     */
    public function __construct(
        public string $studentId,
        public string $firstName,
        public string $lastName,
        public array $subscribedCourseIds,
    ) {}

    public function jsonSerialize(): array
    {
        return [
            'studentId' => $this->studentId,
            'firstName' => $this->firstName,
            'lastName' => $this->lastName,
            'subscribedCourseIds' => $this->subscribedCourseIds,
            'subscriptionCount' => count($this->subscribedCourseIds),
        ];
    }
}
