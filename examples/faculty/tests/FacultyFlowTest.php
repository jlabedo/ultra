<?php

declare(strict_types=1);

namespace App\Tests;

use App\Faculty\Infrastructure\Notification\RecordingNotificationService;
use App\Faculty\Query\GetCourseStatsByIdQuery;
use App\Faculty\Query\GetFacultyOverviewQuery;
use App\Faculty\Query\GetStudentProfileByIdQuery;
use App\Faculty\Query\ListCourseStatsQuery;
use App\Faculty\Query\ListStudentProfilesQuery;
use App\Faculty\Support\EventTypes;
use App\Faculty\Support\FacultyIds;
use App\Faculty\Write\CreateCourseCommand;
use App\Faculty\Write\EnrollStudentInFacultyCommand;
use App\Faculty\Write\SubscribeStudentToCourseCommand;
use App\Faculty\Write\UnsubscribeStudentFromCourseCommand;
use Ultra\EventStore\Event;
use Ultra\EventStore\EventStore;
use Ultra\MessageHandling\Async\QueueWorker;
use Ultra\MessageHandling\MediatorInterface;
use Ultra\StatefulMessageHandling\Exception\InvariantViolation;
use Ultra\UnitOfWork\UnitOfWork;

/**
 * @internal
 *
 * @coversNothing
 */
final class FacultyFlowTest extends FacultyKernelTestCase
{
    public function test_it_enrolls_students_creates_courses_and_builds_read_models(): void
    {
        self::bootKernel();

        /** @var MediatorInterface $mediator */
        $mediator = self::getContainer()->get(MediatorInterface::class);

        /** @var RecordingNotificationService $notifications */
        $notifications = self::getContainer()->get(RecordingNotificationService::class);

        $mediator->send(new CreateCourseCommand('course-1', 'Event Sourcing', 2));
        $mediator->send(new EnrollStudentInFacultyCommand('student-1', 'Ada', 'Lovelace'));
        $mediator->send(new SubscribeStudentToCourseCommand('course-1', 'student-1'));
        $this->drainQueue();

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

    public function test_it_rejects_subscriptions_when_course_is_full(): void
    {
        self::bootKernel();

        /** @var MediatorInterface $mediator */
        $mediator = self::getContainer()->get(MediatorInterface::class);

        $mediator->send(new CreateCourseCommand('course-1', 'DDD', 1));
        $mediator->send(new EnrollStudentInFacultyCommand('student-1', 'Ada', 'Lovelace'));
        $mediator->send(new EnrollStudentInFacultyCommand('student-2', 'Grace', 'Hopper'));
        $mediator->send(new SubscribeStudentToCourseCommand('course-1', 'student-1'));

        $this->expectException(InvariantViolation::class);
        $this->expectExceptionMessage('course.full');

        $mediator->send(new SubscribeStudentToCourseCommand('course-1', 'student-2'));
    }

    public function test_it_sends_an_admin_notification_when_all_courses_are_fully_booked(): void
    {
        self::bootKernel();

        /** @var MediatorInterface $mediator */
        $mediator = self::getContainer()->get(MediatorInterface::class);

        /** @var RecordingNotificationService $notifications */
        $notifications = self::getContainer()->get(RecordingNotificationService::class);

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
            static fn($notification): bool => (
                'admin' === $notification->recipientId
                && 'All courses are fully booked now.' === $notification->message
            ),
        ));
    }

    public function test_it_returns_null_for_missing_course_and_student_read_models(): void
    {
        self::bootKernel();

        /** @var MediatorInterface $mediator */
        $mediator = self::getContainer()->get(MediatorInterface::class);

        self::assertNull($mediator->send(new GetCourseStatsByIdQuery('missing-course')));
        self::assertNull($mediator->send(new GetStudentProfileByIdQuery('missing-student')));
    }

    public function test_it_keeps_sorted_lists_and_updates_read_models_after_unsubscribe(): void
    {
        self::bootKernel();

        /** @var MediatorInterface $mediator */
        $mediator = self::getContainer()->get(MediatorInterface::class);

        $mediator->send(new CreateCourseCommand('course-b', 'DDD', 2));
        $mediator->send(new CreateCourseCommand('course-a', 'Axon', 2));
        $mediator->send(new EnrollStudentInFacultyCommand('student-b', 'Grace', 'Hopper'));
        $mediator->send(new EnrollStudentInFacultyCommand('student-a', 'Ada', 'Lovelace'));
        $mediator->send(new SubscribeStudentToCourseCommand('course-b', 'student-b'));
        $mediator->send(new SubscribeStudentToCourseCommand('course-a', 'student-a'));
        $mediator->send(new UnsubscribeStudentFromCourseCommand('course-b', 'student-b'));
        $this->drainQueue();

        $courses = $mediator->send(new ListCourseStatsQuery());
        $students = $mediator->send(new ListStudentProfilesQuery());
        $overview = $mediator->send(new GetFacultyOverviewQuery());

        self::assertSame(
            ['course-a', 'course-b'],
            array_map(static fn($course): string => $course->courseId, $courses),
        );
        self::assertSame([1, 0], array_map(static fn($course): int => $course->subscribedStudents, $courses));
        self::assertSame(
            ['student-a', 'student-b'],
            array_map(static fn($student): string => $student->studentId, $students),
        );
        self::assertSame(
            [['course-a'], []],
            array_map(static fn($student): array => $student->subscribedCourseIds, $students),
        );
        self::assertSame(
            ['course-a', 'course-b'],
            array_map(static fn($course): string => $course->courseId, $overview->courses),
        );
        self::assertSame(
            ['student-a', 'student-b'],
            array_map(static fn($student): string => $student->studentId, $overview->students),
        );
    }

    public function test_it_preserves_tracked_subscriptions_when_student_enrollment_replays(): void
    {
        self::bootKernel();

        /** @var EventStore $eventStore */
        $eventStore = self::getContainer()->get(EventStore::class);

        /** @var MediatorInterface $mediator */
        $mediator = self::getContainer()->get(MediatorInterface::class);

        $eventStore->append([
            new Event(
                EventTypes::COURSE_CREATED,
                [
                    'facultyId' => FacultyIds::DEFAULT,
                    'courseId' => 'course-1',
                    'name' => 'Event Sourcing',
                    'capacity' => 3,
                ],
                [
                    'faculty' => FacultyIds::DEFAULT,
                    'course' => 'course-1',
                ],
            ),
            new Event(
                EventTypes::STUDENT_ENROLLED_IN_FACULTY,
                [
                    'facultyId' => FacultyIds::DEFAULT,
                    'studentId' => 'student-1',
                    'firstName' => 'Ada',
                    'lastName' => 'Lovelace',
                ],
                [
                    'faculty' => FacultyIds::DEFAULT,
                    'student' => 'student-1',
                ],
            ),
            new Event(
                EventTypes::STUDENT_SUBSCRIBED_TO_COURSE,
                [
                    'facultyId' => FacultyIds::DEFAULT,
                    'courseId' => 'course-1',
                    'studentId' => 'student-1',
                ],
                [
                    'faculty' => FacultyIds::DEFAULT,
                    'course' => 'course-1',
                    'student' => 'student-1',
                ],
            ),
            new Event(
                EventTypes::STUDENT_ENROLLED_IN_FACULTY,
                [
                    'facultyId' => FacultyIds::DEFAULT,
                    'studentId' => 'student-1',
                    'firstName' => 'Augusta Ada',
                    'lastName' => 'King',
                ],
                [
                    'faculty' => FacultyIds::DEFAULT,
                    'student' => 'student-1',
                ],
            ),
        ]);
        self::getContainer()->get(UnitOfWork::class)->flush();
        $this->drainQueue();

        $student = $mediator->send(new GetStudentProfileByIdQuery('student-1'));

        self::assertNotNull($student);
        self::assertSame('Augusta Ada', $student->firstName);
        self::assertSame('King', $student->lastName);
        self::assertSame(['course-1'], $student->subscribedCourseIds);
    }

    private function drainQueue(): void
    {
        /** @var QueueWorker $worker */
        $worker = self::getContainer()->get('test.queue_worker');
        $worker->drain();
    }
}
