<?php

declare(strict_types=1);

namespace Ultra\Projection\Tests\Support;

use Ultra\Events\Attribute\Event as EventAttribute;
use Ultra\Projection\Attribute\Projection;
use Ultra\Projection\Attribute\ProjectionHandler;

#[EventAttribute('backfill.course.created')]
final readonly class BackfillCourseCreated
{
    public function __construct(public string $courseId) {}
}

#[Projection(name: 'backfill_projection', partitionBy: 'courseId')]
final class BackfillProjection
{
    /** @var list<string> */
    public array $courseIds = [];

    #[ProjectionHandler]
    public function onCourseCreated(BackfillCourseCreated $event): void
    {
        $this->courseIds[] = $event->courseId;
    }
}

