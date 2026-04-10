<?php

declare(strict_types=1);

namespace Ultra\DecisionEngine\Tests;

use Symfony\Component\DependencyInjection\Container;
use Ultra\DecisionEngine\Exception\AmbiguousTagError;
use Ultra\DecisionEngine\Exception\DuplicateEventSourcingHandlerError;
use Ultra\DecisionEngine\Exception\InvalidMatchTagError;
use Ultra\DecisionEngine\Exception\MissingEventSourcingHandlerError;
use Ultra\DecisionEngine\Exception\MissingTypeHintError;
use Ultra\DecisionEngine\Exception\NoTagOverlapError;
use Ultra\DecisionEngine\Exception\UnnecessaryMatchTagError;
use Ultra\DecisionEngine\Tests\Support\CompilerAmbiguousEvent;
use Ultra\DecisionEngine\Tests\Support\CompilerAmbiguousState;
use Ultra\DecisionEngine\Tests\Support\CompilerCourseCapacityChanged;
use Ultra\DecisionEngine\Tests\Support\CompilerCourseCreated;
use Ultra\DecisionEngine\Tests\Support\CompilerDuplicateHandlerState;
use Ultra\DecisionEngine\Tests\Support\CompilerInvalidMatchTagState;
use Ultra\DecisionEngine\Tests\Support\CompilerMissingHandlerAttributeState;
use Ultra\DecisionEngine\Tests\Support\CompilerMissingTypeHintState;
use Ultra\DecisionEngine\Tests\Support\CompilerNoOverlapEvent;
use Ultra\DecisionEngine\Tests\Support\CompilerNoOverlapState;
use Ultra\DecisionEngine\Tests\Support\CompilerPairConfirmed;
use Ultra\DecisionEngine\Tests\Support\CompilerSingleTagEvent;
use Ultra\DecisionEngine\Tests\Support\CompilerStudentEnrolled;
use Ultra\DecisionEngine\Tests\Support\CompilerStudentSubscribed;
use Ultra\DecisionEngine\Tests\Support\CompilerStudentUnsubscribed;
use Ultra\DecisionEngine\Tests\Support\CompilerUnnecessaryMatchTagState;
use Ultra\DecisionEngine\Tests\Support\DecisionEngineFunctionalTestCase;

require_once __DIR__.'/Support/EventSourcedEntityFixtures.php';

/**
 * @internal
 *
 * @coversNothing
 */
final class EventSourcedEntityCompilationTest extends DecisionEngineFunctionalTestCase
{
    public function test_container_compilation_rejects_handlers_whose_events_share_no_tags_with_the_entity(): void
    {
        $this->expectException(NoTagOverlapError::class);

        $this->bootCompilerContainer(CompilerNoOverlapState::class);
    }

    public function test_container_compilation_rejects_ambiguous_handlers_without_match_tags(): void
    {
        $this->expectException(AmbiguousTagError::class);

        $this->bootCompilerContainer(CompilerAmbiguousState::class);
    }

    public function test_container_compilation_rejects_unnecessary_match_tags_for_single_tag_handlers(): void
    {
        $this->expectException(UnnecessaryMatchTagError::class);

        $this->bootCompilerContainer(CompilerUnnecessaryMatchTagState::class);
    }

    public function test_container_compilation_rejects_match_tags_outside_the_entity_and_event_intersection(): void
    {
        $this->expectException(InvalidMatchTagError::class);

        $this->bootCompilerContainer(CompilerInvalidMatchTagState::class);
    }

    public function test_container_compilation_rejects_match_tags_on_methods_that_are_not_handlers(): void
    {
        $this->expectException(MissingEventSourcingHandlerError::class);

        $this->bootCompilerContainer(CompilerMissingHandlerAttributeState::class);
    }

    public function test_container_compilation_rejects_handlers_without_a_class_typed_event_parameter(): void
    {
        $this->expectException(MissingTypeHintError::class);

        $this->bootCompilerContainer(CompilerMissingTypeHintState::class);
    }

    public function test_container_compilation_rejects_duplicate_handlers_for_the_same_event_type(): void
    {
        $this->expectException(DuplicateEventSourcingHandlerError::class);

        $this->bootCompilerContainer(CompilerDuplicateHandlerState::class);
    }

    /**
     * @param class-string ...$entityClasses
     */
    private function bootCompilerContainer(string ...$entityClasses): Container
    {
        return $this->decisionUltraLight(self::eventClasses())
            ->withClass(...$entityClasses)
            ->boot();
    }

    /**
     * @return list<class-string>
     */
    private static function eventClasses(): array
    {
        return [
            CompilerCourseCreated::class,
            CompilerCourseCapacityChanged::class,
            CompilerStudentEnrolled::class,
            CompilerStudentSubscribed::class,
            CompilerStudentUnsubscribed::class,
            CompilerPairConfirmed::class,
            CompilerNoOverlapEvent::class,
            CompilerAmbiguousEvent::class,
            CompilerSingleTagEvent::class,
        ];
    }
}
