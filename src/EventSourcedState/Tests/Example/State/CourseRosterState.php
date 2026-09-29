<?php

declare(strict_types=1);

namespace Ultra\EventSourcedState\Tests\Example\State;

use Ultra\EventSourcedState\Attribute\EventSourcedEntity;
use Ultra\EventSourcedState\Attribute\EventSourcingHandler;
use Ultra\EventSourcedState\Attribute\MatchTag;
use Ultra\EventSourcedState\Tests\Example\Event\StudentSubscribed;
use Ultra\EventSourcedState\Tests\Example\Event\StudentTransferred;

/**
 * Students of one course, including those transferred in or out: a transfer is loaded both as the new course and,
 * through the alias, as the previous course.
 */
#[EventSourcedEntity(tags: ['course'], aliases: ['previousCourse' => 'course'])]
final class CourseRosterState
{
    /** @var list<string> */
    public array $studentIds = [];

    #[EventSourcingHandler]
    public function onSubscribed(StudentSubscribed $event): void
    {
        $this->studentIds[] = $event->studentId;
    }

    #[EventSourcingHandler]
    #[MatchTag('course')]
    #[MatchTag('previousCourse')]
    public function onTransferred(StudentTransferred $event): void
    {
        if (in_array($event->studentId, $this->studentIds, true)) {
            $this->studentIds = array_values(array_diff($this->studentIds, [$event->studentId]));
        } else {
            $this->studentIds[] = $event->studentId;
        }
    }
}
