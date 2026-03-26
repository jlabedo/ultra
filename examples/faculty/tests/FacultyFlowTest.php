<?php

declare(strict_types=1);

namespace App\Tests;

use App\Faculty\Infrastructure\Notification\RecordingNotificationService;
use App\Faculty\Query\GetCourseStatsByIdQuery;
use App\Faculty\Query\GetFacultyOverviewQuery;
use App\Faculty\Query\GetStudentProfileByIdQuery;
use App\Faculty\Write\CreateCourse\CreateCourseCommand;
use App\Faculty\Write\EnrollStudent\EnrollStudentInFacultyCommand;
use App\Faculty\Write\SubscribeStudentToCourse\SubscribeStudentToCourseCommand;
use Ultra\DecisionEngine\Exception\InvariantViolation;
use Ultra\Mediator\MediatorInterface;

final class FacultyFlowTest extends FacultyKernelTestCase
{
    public function testItEnrollsStudentsCreatesCoursesAndBuildsReadModels(): void
    {
        self::bootKernel();

        /** @var MediatorInterface $mediator */
        $mediator = static::getContainer()->get(MediatorInterface::class);
        /** @var RecordingNotificationService $notifications */
        $notifications = static::getContainer()->get(RecordingNotificationService::class);

        $mediator->send(new CreateCourseCommand('course-1', 'Event Sourcing', 2));
        $mediator->send(new EnrollStudentInFacultyCommand('student-1', 'Ada', 'Lovelace'));
        $mediator->send(new SubscribeStudentToCourseCommand('course-1', 'student-1'));

        $course = $mediator->send(new GetCourseStatsByIdQuery('course-1'));
        $student = $mediator->send(new GetStudentProfileByIdQuery('student-1'));
        $overview = $mediator->send(new GetFacultyOverviewQuery());

        self::assertNotNull($course);
        self::assertSame('course-1', $course->courseId);
        self::assertSame(1, $course->subscribedStudents);

        self::assertNotNull($student);
        self::assertSame(['course-1'], $student->subscribedCourseIds);

        self::assertCount(1, $overview->courses);
        self::assertCount(1, $overview->students);
        self::assertCount(1, $notifications->sent());
        self::assertSame('student-1', $notifications->sent()[0]->recipientId);
    }

    public function testItRejectsSubscriptionsWhenCourseIsFull(): void
    {
        self::bootKernel();

        /** @var MediatorInterface $mediator */
        $mediator = static::getContainer()->get(MediatorInterface::class);

        $mediator->send(new CreateCourseCommand('course-1', 'DDD', 1));
        $mediator->send(new EnrollStudentInFacultyCommand('student-1', 'Ada', 'Lovelace'));
        $mediator->send(new EnrollStudentInFacultyCommand('student-2', 'Grace', 'Hopper'));
        $mediator->send(new SubscribeStudentToCourseCommand('course-1', 'student-1'));

        $this->expectException(InvariantViolation::class);
        $this->expectExceptionMessage('course.full');

        $mediator->send(new SubscribeStudentToCourseCommand('course-1', 'student-2'));
    }

    public function testItSendsAnAdminNotificationWhenAllCoursesAreFullyBooked(): void
    {
        self::bootKernel();

        /** @var MediatorInterface $mediator */
        $mediator = static::getContainer()->get(MediatorInterface::class);
        /** @var RecordingNotificationService $notifications */
        $notifications = static::getContainer()->get(RecordingNotificationService::class);

        $mediator->send(new CreateCourseCommand('course-1', 'Axon', 1));
        $mediator->send(new CreateCourseCommand('course-2', 'Symfony', 1));
        $mediator->send(new EnrollStudentInFacultyCommand('student-1', 'Ada', 'Lovelace'));
        $mediator->send(new EnrollStudentInFacultyCommand('student-2', 'Grace', 'Hopper'));

        $mediator->send(new SubscribeStudentToCourseCommand('course-1', 'student-1'));

        self::assertCount(1, $notifications->sent());

        $mediator->send(new SubscribeStudentToCourseCommand('course-2', 'student-2'));

        self::assertCount(3, $notifications->sent());
        self::assertTrue(array_any(
            $notifications->sent(),
            static fn ($notification): bool => 'admin' === $notification->recipientId
                && 'All courses are fully booked now.' === $notification->message,
        ));
    }
}
