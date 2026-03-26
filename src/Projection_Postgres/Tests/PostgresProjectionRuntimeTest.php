<?php

declare(strict_types=1);

namespace Ultra\Projection_Postgres\Tests;

use Doctrine\DBAL\Connection;
use Ultra\EventStore\AppendableEvent;
use Ultra\EventStore\EventStore;
use Ultra\EventStore\StoredEvent;
use Ultra\EventStore_Postgres\Tests;
use Ultra\Projection\Attribute\ProjectionHandler;
use Ultra\Projection\DeliveryMode;
use Ultra\Projection\EventFeed;
use Ultra\Projection\PartitionStrategy;
use Ultra\Projection\Projection;
use Ultra\Projection\ProjectionPlan;
use Ultra\Projection\ProjectionPlanValidator;
use Ultra\Projection_Postgres\PostgresDirectProjectionRunner;
use Ultra\Projection_Postgres\PostgresInboxPartitionWorker;
use Ultra\Projection_Postgres\PostgresProjectionRouter;
use Ultra\Projection_Postgres\PostgresProjectionRuntimeRepository;

require_once __DIR__.'/PostgresProjectionTestSupport.php';
require_once __DIR__.'/../../EventStore_Postgres/Tests/PostgresTestSupport.php';

#[Projection('course_names')]
final class CourseNamesProjection
{
    public function __construct(
        private readonly Connection $connection,
        private readonly string $tableName,
    ) {}

    #[ProjectionHandler('course.created')]
    public function onCourseCreated(StoredEvent $event): void
    {
        $this->connection->executeStatement(
            sprintf(
                'INSERT INTO %s (course_id, name, last_event_id)
                 VALUES (?, ?, ?)
                 ON CONFLICT (course_id) DO UPDATE
                 SET name = EXCLUDED.name, last_event_id = EXCLUDED.last_event_id',
                $this->tableName,
            ),
            [$event->payload['courseId'], $event->payload['name'], $event->eventId],
        );
    }
}

#[Projection('student_activity')]
final class StudentActivityProjection
{
    public function __construct(
        private readonly Connection $connection,
        private readonly string $tableName,
    ) {}

    #[ProjectionHandler('student.subscribed')]
    public function onStudentSubscribed(StoredEvent $event): void
    {
        $this->connection->executeStatement(
            sprintf(
                'INSERT INTO %s (student_id, event_id, slot)
                 VALUES (?, ?, ?)',
                $this->tableName,
            ),
            [$event->payload['studentId'], $event->eventId, $event->payload['slot']],
        );
    }
}

test('direct runner replays ordered events and advances the direct checkpoint', function () {
    withPostgresProjectionRuntime(function (
        Connection $connection,
        EventStore $store,
        EventFeed $feed,
        PostgresProjectionRuntimeRepository $repository,
        string $eventStorePrefix,
        string $projectionPrefix,
    ): void {
        $tableName = $projectionPrefix.'course_names_view';
        $connection->executeStatement(sprintf(
            'CREATE TABLE %s (
                course_id TEXT PRIMARY KEY,
                name TEXT NOT NULL,
                last_event_id BIGINT NOT NULL
            )',
            $tableName,
        ));

        $store->append([
            new AppendableEvent(
                eventType: 'course.created',
                payload: ['courseId' => 'course-1', 'name' => 'Math 101'],
                domainIds: ['courseId' => 'course-1'],
            ),
            new AppendableEvent(
                eventType: 'course.created',
                payload: ['courseId' => 'course-2', 'name' => 'Physics 201'],
                domainIds: ['courseId' => 'course-2'],
            ),
        ]);

        $plan = new ProjectionPlan(
            new CourseNamesProjection($connection, $tableName),
        );

        $runner = new PostgresDirectProjectionRunner(
            connection: $connection,
            eventFeed: $feed,
            repository: $repository,
            validator: new ProjectionPlanValidator(Tests\postgresTestTopologyProvider()),
        );

        expect($runner->runOnce($plan, 100))->toBe(2)
            ->and($runner->runOnce($plan, 100))->toBe(0);

        $rows = $connection->fetchAllAssociative(sprintf(
            'SELECT course_id, name, last_event_id FROM %s ORDER BY course_id ASC',
            $tableName,
        ));
        $state = $repository->fetchStateRow($plan->name(), $plan->version(), 'direct');

        expect($rows)->toBe([
            ['course_id' => 'course-1', 'name' => 'Math 101', 'last_event_id' => '1'],
            ['course_id' => 'course-2', 'name' => 'Physics 201', 'last_event_id' => '2'],
        ])
            ->and($state)->toBe([
                'last_event_id' => 2,
                'last_error' => null,
            ]);
    });
});

test('router and partition workers process each partition in order while sharing one projection definition', function () {
    withPostgresProjectionRuntime(function (
        Connection $connection,
        EventStore $store,
        EventFeed $feed,
        PostgresProjectionRuntimeRepository $repository,
        string $eventStorePrefix,
        string $projectionPrefix,
    ): void {
        $tableName = $projectionPrefix.'student_activity_view';
        $connection->executeStatement(sprintf(
            'CREATE TABLE %s (
                student_id TEXT NOT NULL,
                event_id BIGINT NOT NULL,
                slot INTEGER NOT NULL,
                PRIMARY KEY (student_id, event_id)
            )',
            $tableName,
        ));

        $store->append([
            new AppendableEvent(
                eventType: 'student.subscribed',
                payload: ['studentId' => 'student-1', 'courseId' => 'course-1', 'slot' => 1],
                domainIds: ['facultyId' => 'faculty-1', 'courseId' => 'course-1', 'studentId' => 'student-1'],
            ),
            new AppendableEvent(
                eventType: 'student.subscribed',
                payload: ['studentId' => 'student-2', 'courseId' => 'course-1', 'slot' => 1],
                domainIds: ['facultyId' => 'faculty-1', 'courseId' => 'course-1', 'studentId' => 'student-2'],
            ),
            new AppendableEvent(
                eventType: 'student.subscribed',
                payload: ['studentId' => 'student-1', 'courseId' => 'course-2', 'slot' => 2],
                domainIds: ['facultyId' => 'faculty-1', 'courseId' => 'course-2', 'studentId' => 'student-1'],
            ),
            new AppendableEvent(
                eventType: 'student.subscribed',
                payload: ['studentId' => 'student-2', 'courseId' => 'course-2', 'slot' => 2],
                domainIds: ['facultyId' => 'faculty-1', 'courseId' => 'course-2', 'studentId' => 'student-2'],
            ),
        ]);

        $plan = new ProjectionPlan(
            new StudentActivityProjection($connection, $tableName),
            DeliveryMode::partitioned(PartitionStrategy::byDomainId('studentId')),
        );

        $validator = new ProjectionPlanValidator(Tests\postgresTestTopologyProvider());

        $router = new PostgresProjectionRouter(
            connection: $connection,
            eventFeed: $feed,
            repository: $repository,
            validator: $validator,
        );
        $worker = new PostgresInboxPartitionWorker(
            connection: $connection,
            eventFeed: $feed,
            repository: $repository,
            validator: $validator,
        );

        expect($router->routeOnce($plan, 100))->toBe(4);

        $inboxRows = $repository->fetchInboxRows($plan->name(), $plan->version());
        expect($inboxRows)->toBe([
            ['partition_key' => '{"studentId":"student-1"}', 'event_id' => 1],
            ['partition_key' => '{"studentId":"student-1"}', 'event_id' => 3],
            ['partition_key' => '{"studentId":"student-2"}', 'event_id' => 2],
            ['partition_key' => '{"studentId":"student-2"}', 'event_id' => 4],
        ]);

        expect($worker->runOnce($plan, 100))->toBe(2)
            ->and($worker->runOnce($plan, 100))->toBe(2)
            ->and($worker->runOnce($plan, 100))->toBe(0);

        $rows = $connection->fetchAllAssociative(sprintf(
            'SELECT student_id, event_id, slot FROM %s ORDER BY student_id ASC, event_id ASC',
            $tableName,
        ));

        expect($rows)->toBe([
            ['student_id' => 'student-1', 'event_id' => '1', 'slot' => 1],
            ['student_id' => 'student-1', 'event_id' => '3', 'slot' => 2],
            ['student_id' => 'student-2', 'event_id' => '2', 'slot' => 1],
            ['student_id' => 'student-2', 'event_id' => '4', 'slot' => 2],
        ])
            ->and($repository->fetchStateRow($plan->name(), $plan->version(), 'router'))->toBe([
                'last_event_id' => 4,
                'last_error' => null,
            ])
            ->and($repository->fetchPartitionRow($plan->name(), $plan->version(), '{"studentId":"student-1"}'))->toBe([
                'partition_key' => '{"studentId":"student-1"}',
                'last_event_id' => 3,
                'last_error' => null,
            ])
            ->and($repository->fetchPartitionRow($plan->name(), $plan->version(), '{"studentId":"student-2"}'))->toBe([
                'partition_key' => '{"studentId":"student-2"}',
                'last_event_id' => 4,
                'last_error' => null,
            ]);
    });
});
