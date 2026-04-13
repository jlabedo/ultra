<?php

declare(strict_types=1);

namespace App\Faculty\Read;

final readonly class CourseStatsView implements \JsonSerializable
{
    public function __construct(
        public string $courseId,
        public string $name,
        public int $capacity,
        public int $subscribedStudents,
    ) {}

    public function jsonSerialize(): array
    {
        return [
            'courseId' => $this->courseId,
            'name' => $this->name,
            'capacity' => $this->capacity,
            'subscribedStudents' => $this->subscribedStudents,
            'availableSpots' => max(0, $this->capacity - $this->subscribedStudents),
            'fullyBooked' => $this->subscribedStudents >= $this->capacity,
        ];
    }
}
