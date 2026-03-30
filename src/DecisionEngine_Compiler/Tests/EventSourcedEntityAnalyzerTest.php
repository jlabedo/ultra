<?php

declare(strict_types=1);

namespace Ultra\DecisionEngine_Compiler\Tests;

use Ultra\DecisionEngine\Attribute\EventSourcedEntity;
use Ultra\DecisionEngine\Attribute\EventSourcingHandler;
use Ultra\DecisionEngine\Attribute\MatchTag;
use Ultra\DecisionEngine\Exception\AmbiguousTagError;
use Ultra\DecisionEngine\Exception\DuplicateEventSourcingHandlerError;
use Ultra\DecisionEngine\Exception\InvalidMatchTagError;
use Ultra\DecisionEngine\Exception\MissingEventSourcingHandlerError;
use Ultra\DecisionEngine\Exception\MissingTypeHintError;
use Ultra\DecisionEngine\Exception\NoTagOverlapError;
use Ultra\DecisionEngine\Exception\UnnecessaryMatchTagError;
use Ultra\DecisionEngine_Compiler\EventSourcedEntityAnalyzer;
use Ultra\Events\Attribute\DomainIdentifier as DomainIdentifierAttribute;
use Ultra\Events\Attribute\Event;

#[Event('analyzer.no_overlap')]
final readonly class AnalyzerNoOverlapEvent
{
    public function __construct(
        #[DomainIdentifierAttribute('faculty')]
        public string $facultyId,
    ) {}
}

#[Event('analyzer.ambiguous')]
final readonly class AnalyzerAmbiguousEvent
{
    public function __construct(
        #[DomainIdentifierAttribute('course')]
        public string $courseId,
        #[DomainIdentifierAttribute('student')]
        public string $studentId,
    ) {}
}

#[Event('analyzer.single')]
final readonly class AnalyzerSingleTagEvent
{
    public function __construct(
        #[DomainIdentifierAttribute('course')]
        public string $courseId,
    ) {}
}

#[EventSourcedEntity(tags: ['course', 'student'])]
final class AnalyzerNoOverlapState
{
    #[EventSourcingHandler]
    public function onEvent(AnalyzerNoOverlapEvent $event): void {}
}

#[EventSourcedEntity(tags: ['course', 'student'])]
final class AnalyzerAmbiguousState
{
    #[EventSourcingHandler]
    public function onEvent(AnalyzerAmbiguousEvent $event): void {}
}

#[EventSourcedEntity(tags: ['course', 'student'])]
final class AnalyzerUnnecessaryMatchTagState
{
    #[EventSourcingHandler]
    #[MatchTag('course')]
    public function onEvent(AnalyzerSingleTagEvent $event): void {}
}

#[EventSourcedEntity(tags: ['course', 'student'])]
final class AnalyzerInvalidMatchTagState
{
    #[EventSourcingHandler]
    #[MatchTag('faculty')]
    public function onEvent(AnalyzerAmbiguousEvent $event): void {}
}

#[EventSourcedEntity(tags: ['course'])]
final class AnalyzerMissingHandlerAttributeState
{
    #[MatchTag('course')]
    public function onEvent(AnalyzerSingleTagEvent $event): void {}
}

#[EventSourcedEntity(tags: ['course'])]
final class AnalyzerMissingTypeHintState
{
    #[EventSourcingHandler]
    public function onEvent(string $event): void {}
}

#[EventSourcedEntity(tags: ['course'])]
final class AnalyzerDuplicateHandlerState
{
    #[EventSourcingHandler]
    public function onFirst(AnalyzerSingleTagEvent $event): void {}

    #[EventSourcingHandler]
    public function onSecond(AnalyzerSingleTagEvent $event): void {}
}

test('analyzer rejects handlers whose events share no tags with the entity', function () {
    $analyzer = new EventSourcedEntityAnalyzer();

    expect(fn () => $analyzer->analyze(AnalyzerNoOverlapState::class))
        ->toThrow(NoTagOverlapError::class);
});

test('analyzer rejects ambiguous handlers without match tags', function () {
    $analyzer = new EventSourcedEntityAnalyzer();

    expect(fn () => $analyzer->analyze(AnalyzerAmbiguousState::class))
        ->toThrow(AmbiguousTagError::class);
});

test('analyzer rejects unnecessary match tags for single-tag handlers', function () {
    $analyzer = new EventSourcedEntityAnalyzer();

    expect(fn () => $analyzer->analyze(AnalyzerUnnecessaryMatchTagState::class))
        ->toThrow(UnnecessaryMatchTagError::class);
});

test('analyzer rejects match tags outside the entity and event intersection', function () {
    $analyzer = new EventSourcedEntityAnalyzer();

    expect(fn () => $analyzer->analyze(AnalyzerInvalidMatchTagState::class))
        ->toThrow(InvalidMatchTagError::class);
});

test('analyzer rejects match tags on methods that are not handlers', function () {
    $analyzer = new EventSourcedEntityAnalyzer();

    expect(fn () => $analyzer->analyze(AnalyzerMissingHandlerAttributeState::class))
        ->toThrow(MissingEventSourcingHandlerError::class);
});

test('analyzer rejects handlers without a class-typed event parameter', function () {
    $analyzer = new EventSourcedEntityAnalyzer();

    expect(fn () => $analyzer->analyze(AnalyzerMissingTypeHintState::class))
        ->toThrow(MissingTypeHintError::class);
});

test('analyzer rejects duplicate handlers for the same event type', function () {
    $analyzer = new EventSourcedEntityAnalyzer();

    expect(fn () => $analyzer->analyze(AnalyzerDuplicateHandlerState::class))
        ->toThrow(DuplicateEventSourcingHandlerError::class);
});
