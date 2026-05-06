<?php

declare(strict_types=1);

namespace Ultra\EventSourcedState_Compiler\Tests;

use Ultra\EventSourcedState\Attribute\EventSourcedEntity;
use Ultra\EventSourcedState\Attribute\EventSourcingHandler;
use Ultra\EventSourcedState\Attribute\MatchTag;
use Ultra\EventSourcedState\Exception\AmbiguousTagError;
use Ultra\EventSourcedState\Exception\DuplicateEventSourcingHandlerError;
use Ultra\EventSourcedState\Exception\InvalidMatchTagError;
use Ultra\EventSourcedState\Exception\MissingEventSourcingHandlerError;
use Ultra\EventSourcedState\Exception\MissingTypeHintError;
use Ultra\EventSourcedState\Exception\NoTagOverlapError;
use Ultra\EventSourcedState\Exception\UnnecessaryMatchTagError;
use Ultra\EventSourcedState\Tests\Support\EventSourcedStateFunctionalTestCase;
use Ultra\EventSourcedState\Tests\Support\EventSourcedStateTestContainer;
use Ultra\Events\Attribute\DomainIdentifier;
use Ultra\Events\Attribute\Event;
use Ultra\Testing\UltraLight;

#[Event('compiler.no_overlap')]
final readonly class CompilerNoOverlapEvent
{
    public function __construct(
        #[DomainIdentifier('faculty')]
        public string $facultyId,
    ) {}
}

#[Event('compiler.ambiguous')]
final readonly class CompilerAmbiguousEvent
{
    public function __construct(
        #[DomainIdentifier('course')]
        public string $courseId,
        #[DomainIdentifier('student')]
        public string $studentId,
    ) {}
}

#[Event('compiler.single')]
final readonly class CompilerSingleTagEvent
{
    public function __construct(
        #[DomainIdentifier('course')]
        public string $courseId,
    ) {}
}

#[EventSourcedEntity(tags: ['course', 'student'])]
final class CompilerNoOverlapState
{
    #[EventSourcingHandler]
    public function onEvent(CompilerNoOverlapEvent $event): void {}
}

#[EventSourcedEntity(tags: ['course', 'student'])]
final class CompilerAmbiguousState
{
    #[EventSourcingHandler]
    public function onEvent(CompilerAmbiguousEvent $event): void {}
}

#[EventSourcedEntity(tags: ['course', 'student'])]
final class CompilerUnnecessaryMatchTagState
{
    #[EventSourcingHandler]
    #[MatchTag('course')]
    public function onEvent(CompilerSingleTagEvent $event): void {}
}

#[EventSourcedEntity(tags: ['course', 'student'])]
final class CompilerInvalidMatchTagState
{
    #[EventSourcingHandler]
    #[MatchTag('faculty')]
    public function onEvent(CompilerAmbiguousEvent $event): void {}
}

#[EventSourcedEntity(tags: ['course'])]
final class CompilerMissingHandlerAttributeState
{
    #[MatchTag('course')]
    public function onEvent(CompilerSingleTagEvent $event): void {}
}

#[EventSourcedEntity(tags: ['course'])]
final class CompilerMissingTypeHintState
{
    #[EventSourcingHandler]
    public function onEvent(string $event): void {}
}

#[EventSourcedEntity(tags: ['course'])]
final class CompilerDuplicateHandlerState
{
    #[EventSourcingHandler]
    public function onFirst(CompilerSingleTagEvent $event): void {}

    #[EventSourcingHandler]
    public function onSecond(CompilerSingleTagEvent $event): void {}
}

/**
 * @internal
 *
 * @coversNothing
 */
final class EventSourcedEntityCompilationTest extends EventSourcedStateFunctionalTestCase
{
    public function test_container_compilation_rejects_handlers_whose_events_share_no_tags_with_the_entity(): void
    {
        $this->expectException(NoTagOverlapError::class);

        $this->ultraLight()
            ->withClass(CompilerNoOverlapState::class)
            ->boot()
        ;
    }

    public function test_container_compilation_rejects_ambiguous_handlers_without_match_tags(): void
    {
        $this->expectException(AmbiguousTagError::class);

        $this->ultraLight()
            ->withClass(CompilerAmbiguousState::class)
            ->boot()
        ;
    }

    public function test_container_compilation_rejects_unnecessary_match_tags_for_single_tag_handlers(): void
    {
        $this->expectException(UnnecessaryMatchTagError::class);

        $this->ultraLight()
            ->withClass(CompilerUnnecessaryMatchTagState::class)
            ->boot()
        ;
    }

    public function test_container_compilation_rejects_match_tags_outside_the_entity_and_event_intersection(): void
    {
        $this->expectException(InvalidMatchTagError::class);

        $this->ultraLight()
            ->withClass(CompilerInvalidMatchTagState::class)
            ->boot()
        ;
    }

    public function test_container_compilation_rejects_match_tags_on_methods_that_are_not_handlers(): void
    {
        $this->expectException(MissingEventSourcingHandlerError::class);

        $this->ultraLight()
            ->withClass(CompilerMissingHandlerAttributeState::class)
            ->boot()
        ;
    }

    public function test_container_compilation_rejects_handlers_without_a_class_typed_event_parameter(): void
    {
        $this->expectException(MissingTypeHintError::class);

        $this->ultraLight()
            ->withClass(CompilerMissingTypeHintState::class)
            ->boot()
        ;
    }

    public function test_container_compilation_rejects_duplicate_handlers_for_the_same_event_type(): void
    {
        $this->expectException(DuplicateEventSourcingHandlerError::class);

        $this->ultraLight()
            ->withClass(CompilerDuplicateHandlerState::class)
            ->boot()
        ;
    }

    /**
     * @return UltraLight<EventSourcedStateTestContainer>
     */
    private function ultraLight(): UltraLight
    {
        return $this->eventSourcedStateUltraLight(self::eventClasses());
    }

    /**
     * @return list<class-string>
     */
    private static function eventClasses(): array
    {
        return [
            CompilerNoOverlapEvent::class,
            CompilerAmbiguousEvent::class,
            CompilerSingleTagEvent::class,
        ];
    }
}
