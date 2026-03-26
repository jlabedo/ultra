<?php

declare(strict_types=1);

namespace App\Faculty\Support;

final class EventTypes
{
    public const ALL_COURSES_FULLY_BOOKED_NOTIFICATION_SENT = 'faculty.all_courses_fully_booked_notification_sent';
    public const COURSE_CAPACITY_CHANGED = 'faculty.course_capacity_changed';
    public const COURSE_CREATED = 'faculty.course_created';
    public const STUDENT_ENROLLED_IN_FACULTY = 'faculty.student_enrolled_in_faculty';
    public const STUDENT_SUBSCRIBED_TO_COURSE = 'faculty.student_subscribed_to_course';
    public const STUDENT_UNSUBSCRIBED_FROM_COURSE = 'faculty.student_unsubscribed_from_course';

    private function __construct() {}
}
