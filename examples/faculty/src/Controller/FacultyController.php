<?php

declare(strict_types=1);

namespace App\Controller;

use App\Faculty\Query\GetCourseStatsByIdQuery;
use App\Faculty\Query\GetFacultyOverviewQuery;
use App\Faculty\Query\GetStudentProfileByIdQuery;
use App\Faculty\Query\ListCourseStatsQuery;
use App\Faculty\Query\ListStudentProfilesQuery;
use App\Faculty\Write\ChangeCourseCapacity\ChangeCourseCapacityCommand;
use App\Faculty\Write\CreateCourse\CreateCourseCommand;
use App\Faculty\Write\EnrollStudent\EnrollStudentInFacultyCommand;
use App\Faculty\Write\SubscribeStudentToCourse\SubscribeStudentToCourseCommand;
use App\Faculty\Write\UnsubscribeStudentFromCourse\UnsubscribeStudentFromCourseCommand;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use Ultra\Mediator\MediatorInterface;

final class FacultyController extends AbstractController
{
    public function __construct(
        private readonly MediatorInterface $mediator,
    ) {}

    #[Route('/', methods: ['GET'])]
    public function index(): JsonResponse
    {
        return $this->json([
            'name' => 'Ultra Faculty Example',
            'routes' => [
                'GET /faculty',
                'GET /faculty/courses',
                'GET /faculty/courses/{courseId}',
                'POST /faculty/courses',
                'POST /faculty/courses/{courseId}/capacity',
                'POST /faculty/students',
                'GET /faculty/students',
                'GET /faculty/students/{studentId}',
                'POST /faculty/courses/{courseId}/subscriptions',
                'DELETE /faculty/courses/{courseId}/subscriptions/{studentId}',
            ],
        ]);
    }

    #[Route('/faculty', methods: ['GET'])]
    public function faculty(): JsonResponse
    {
        return $this->json($this->mediator->send(new GetFacultyOverviewQuery()));
    }

    #[Route('/faculty/courses', methods: ['GET'])]
    public function courses(): JsonResponse
    {
        return $this->json($this->mediator->send(new ListCourseStatsQuery()));
    }

    #[Route('/faculty/courses/{courseId}', methods: ['GET'])]
    public function course(string $courseId): JsonResponse
    {
        $course = $this->mediator->send(new GetCourseStatsByIdQuery($courseId));
        if (null === $course) {
            return $this->json(['error' => 'Course not found.'], JsonResponse::HTTP_NOT_FOUND);
        }

        return $this->json($course);
    }

    #[Route('/faculty/courses', methods: ['POST'])]
    public function createCourse(Request $request): JsonResponse
    {
        $payload = $request->toArray();

        $this->mediator->send(new CreateCourseCommand(
            (string) $payload['courseId'],
            (string) $payload['name'],
            (int) $payload['capacity'],
        ));

        return $this->json(
            $this->mediator->send(new GetCourseStatsByIdQuery((string) $payload['courseId'])),
            JsonResponse::HTTP_CREATED,
        );
    }

    #[Route('/faculty/courses/{courseId}/capacity', methods: ['POST'])]
    public function changeCourseCapacity(string $courseId, Request $request): JsonResponse
    {
        $payload = $request->toArray();

        $this->mediator->send(new ChangeCourseCapacityCommand(
            $courseId,
            (int) $payload['capacity'],
        ));

        return $this->json($this->mediator->send(new GetCourseStatsByIdQuery($courseId)));
    }

    #[Route('/faculty/students', methods: ['GET'])]
    public function students(): JsonResponse
    {
        return $this->json($this->mediator->send(new ListStudentProfilesQuery()));
    }

    #[Route('/faculty/students/{studentId}', methods: ['GET'])]
    public function student(string $studentId): JsonResponse
    {
        $student = $this->mediator->send(new GetStudentProfileByIdQuery($studentId));
        if (null === $student) {
            return $this->json(['error' => 'Student not found.'], JsonResponse::HTTP_NOT_FOUND);
        }

        return $this->json($student);
    }

    #[Route('/faculty/students', methods: ['POST'])]
    public function enrollStudent(Request $request): JsonResponse
    {
        $payload = $request->toArray();

        $this->mediator->send(new EnrollStudentInFacultyCommand(
            (string) $payload['studentId'],
            (string) $payload['firstName'],
            (string) $payload['lastName'],
        ));

        return $this->json(
            $this->mediator->send(new GetStudentProfileByIdQuery((string) $payload['studentId'])),
            JsonResponse::HTTP_CREATED,
        );
    }

    #[Route('/faculty/courses/{courseId}/subscriptions', methods: ['POST'])]
    public function subscribeStudent(string $courseId, Request $request): JsonResponse
    {
        $payload = $request->toArray();
        $studentId = (string) $payload['studentId'];

        $this->mediator->send(new SubscribeStudentToCourseCommand($courseId, $studentId));

        return $this->json([
            'course' => $this->mediator->send(new GetCourseStatsByIdQuery($courseId)),
            'student' => $this->mediator->send(new GetStudentProfileByIdQuery($studentId)),
        ]);
    }

    #[Route('/faculty/courses/{courseId}/subscriptions/{studentId}', methods: ['DELETE'])]
    public function unsubscribeStudent(string $courseId, string $studentId): JsonResponse
    {
        $this->mediator->send(new UnsubscribeStudentFromCourseCommand($courseId, $studentId));

        return $this->json([
            'course' => $this->mediator->send(new GetCourseStatsByIdQuery($courseId)),
            'student' => $this->mediator->send(new GetStudentProfileByIdQuery($studentId)),
        ]);
    }
}
