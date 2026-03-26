<?php

declare(strict_types=1);

namespace App\Faculty\Infrastructure\EventStore;

use App\Faculty\Support\EventTypes;
use Ultra\EventStore\EventTopology;
use Ultra\EventStore\StaticEventTopologyProvider;

final readonly class FacultyEventTopologyProvider extends StaticEventTopologyProvider
{
    public function __construct()
    {
        parent::__construct([
            EventTypes::ALL_COURSES_FULLY_BOOKED_NOTIFICATION_SENT => new EventTopology(['faculty']),
            EventTypes::COURSE_CAPACITY_CHANGED => new EventTopology(['faculty', 'course'], [
                'course' => ['faculty'],
            ]),
            EventTypes::COURSE_CREATED => new EventTopology(['faculty', 'course'], [
                'course' => ['faculty'],
            ]),
            EventTypes::STUDENT_ENROLLED_IN_FACULTY => new EventTopology(['faculty', 'student'], [
                'student' => ['faculty'],
            ]),
            EventTypes::STUDENT_SUBSCRIBED_TO_COURSE => new EventTopology(['faculty', 'course', 'student'], [
                'course' => ['faculty'],
                'student' => ['faculty'],
            ]),
            EventTypes::STUDENT_UNSUBSCRIBED_FROM_COURSE => new EventTopology(['faculty', 'course', 'student'], [
                'course' => ['faculty'],
                'student' => ['faculty'],
            ]),
        ]);
    }
}
