<?php

declare(strict_types=1);

namespace Ultra\StatefulMessageHandling\Tests;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use Ultra\EventSourcedState\Tests\Example\Event\CourseCreated;
use Ultra\EventStore\InMemoryEventStore;
use Ultra\EventStore\StreamQuery;
use Ultra\StatefulMessageHandling\Exception\InvariantViolation;
use Ultra\StatefulMessageHandling\Tests\Support\DecisionTestCase;
use Ultra\StatefulMessageHandling\DecisionMode;
use Ultra\StatefulMessageHandling\Violation;

#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(false)]
final class CheckModeTest extends DecisionTestCase
{
    #[DataProvider('checkedDecisions')]
    public function test_check_executes_services_and_reports_violations_without_applying_returned_effects(string $family, bool $invalid): void
    {
        $container = $this->inspectedApplication($family)->boot();
        $violations = [new Violation('first', 'First violation'), new Violation('second', 'Second violation')];
        $failure = $invalid ? new InvariantViolation($violations) : null;
        $effects = [new CourseCreated('course', 'Math', 12), new DecisionOrderState('order', 'paid')];
        $command = match ($family) {
            'events' => new InspectDecisionCourse('course', $effects, $failure),
            'stored' => new InspectDecisionOrder('order', $effects, $failure),
            'mixed' => new InspectMixedDecision('course', 'order', $effects, $failure),
        };

        self::assertEquals($invalid ? $violations : [], $container->get('test.mediator')->send($command, [DecisionMode::CONTEXT_KEY => DecisionMode::CHECK]));
        self::assertSame(1, $container->get(DecisionService::class)->calls);
        if ('stored' !== $family) {
            self::assertSame([], $container->get(InMemoryEventStore::class)->load(StreamQuery::all())->events);
        }
        if ('events' !== $family) {
            self::assertNull($container->get('test.state_store')->load(DecisionOrderState::class, 'order'));
        }
    }

    public static function checkedDecisions(): iterable
    {
        foreach (['events', 'stored', 'mixed'] as $family) {
            yield $family.' success' => [$family, false];
            yield $family.' invariant violation' => [$family, true];
        }
    }

    #[DataProvider('families')]
    public function test_check_propagates_other_exceptions(string $family): void
    {
        $container = $this->inspectedApplication($family)->boot();
        $failure = new \RuntimeException('Service unavailable');
        $command = match ($family) {
            'events' => new InspectDecisionCourse('course', null, $failure),
            'stored' => new InspectDecisionOrder('order', null, $failure),
            'mixed' => new InspectMixedDecision('course', 'order', null, $failure),
        };
        try {
            $container->get('test.mediator')->send($command, [DecisionMode::CONTEXT_KEY => DecisionMode::CHECK]);
            self::fail('Only invariant violations are converted to results.');
        } catch (\RuntimeException $exception) {
            self::assertSame($failure, $exception);
        }
    }

    public static function families(): iterable
    {
        yield 'event-sourced' => ['events'];
        yield 'stored' => ['stored'];
        yield 'mixed' => ['mixed'];
    }

    #[DataProvider('violationLists')]
    public function test_violation_reports_preserve_the_list_message_and_cause(array $violations, string $message): void
    {
        $previous = new \RuntimeException('original');
        $exception = new InvariantViolation($violations, $previous);
        self::assertSame($violations, $exception->violations);
        self::assertSame($message, $exception->getMessage());
        self::assertSame($previous, $exception->getPrevious());
    }

    public static function violationLists(): iterable
    {
        yield 'empty' => [[], ''];
        yield 'collected' => [[new Violation('first', 'One'), new Violation('second', 'Two')], 'first: One; second: Two'];
    }
}
