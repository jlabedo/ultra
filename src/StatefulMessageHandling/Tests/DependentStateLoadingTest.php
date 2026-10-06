<?php

declare(strict_types=1);

namespace Ultra\StatefulMessageHandling\Tests;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use Ultra\EventStore\DomainIdentifier;
use Ultra\EventStore\InMemoryEventStore;
use Ultra\EventStore\StreamQuery;
use Ultra\MappedTable\InMemoryRowDatabase;
use Ultra\StatefulMessageHandling\Exception\InvariantViolation;
use Ultra\StatefulMessageHandling\Tests\Support\DecisionTestCase;
use Ultra\StatefulMessageHandling\DecisionMode;
use Ultra\StatefulMessageHandling\Violation;

#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(false)]
final class DependentStateLoadingTest extends DecisionTestCase
{
    public function test_independent_states_replay_the_shared_round_and_observe_prior_decisions(): void
    {
        $container = $this->courseApplication()->boot();
        $mediator = $container->get('test.mediator');
        $mediator->send(new \Ultra\StatefulMessageHandling\Tests\Example\Command\CreateCourseCommand('course', 'Math', 1));
        self::assertEquals([new \Ultra\EventSourcedState\Tests\Example\Event\StudentSubscribed('course', 'ada')], $mediator->send(new \Ultra\StatefulMessageHandling\Tests\Example\Command\SubscribeStudentToCourseCommand('course', 'ada')));
        self::assertSame(['course.full', 'student.already_subscribed'], array_map(static fn (Violation $v): string => $v->code, $mediator->send(new \Ultra\StatefulMessageHandling\Tests\Example\Command\SubscribeStudentToCourseCommand('course', 'ada'), [DecisionMode::CONTEXT_KEY => DecisionMode::CHECK])));
        try {
            $mediator->send(new \Ultra\StatefulMessageHandling\Tests\Example\Command\SubscribeStudentToCourseCommand('course', 'grace'));
            self::fail('A rejected decision must append no effects.');
        } catch (InvariantViolation $exception) {
            self::assertSame(['course.full'], array_map(static fn (Violation $v): string => $v->code, $exception->violations));
        }
        self::assertEquals([new \Ultra\EventSourcedState\Tests\Example\Event\StudentUnsubscribed('course', 'ada')], $mediator->send(new \Ultra\StatefulMessageHandling\Tests\Example\Command\UnsubscribeStudentFromCourseCommand('course', 'ada')));
        self::assertCount(3, $container->get(InMemoryEventStore::class)->load(StreamQuery::forStream(new DomainIdentifier('course', 'course')))->events);
        self::assertCount(2, $container->get(InMemoryEventStore::class)->load(StreamQuery::forStream(new DomainIdentifier('student', 'ada')))->events);
        self::assertSame([], $mediator->send(new \Ultra\StatefulMessageHandling\Tests\Example\Command\SubscribeStudentToCourseCommand('course', 'ada'), [DecisionMode::CONTEXT_KEY => DecisionMode::CHECK]));
    }

    #[DataProvider('eventBindings')]
    public function test_derived_identifiers_select_the_state_used_by_the_decision(string $commandClass): void
    {
        $container = $this->dependentApplication(JoinDependentCompany::class, $commandClass)->boot();
        $mediator = $container->get('test.mediator');
        $mediator->send(new JoinDependentCompany('ada', 'acme'));
        $mediator->send(new JoinDependentCompany('grace', 'acme'));
        $command = TakeDependentSeatForCompany::class === $commandClass ? new $commandClass('ada', 'acme', '2026-09') : new $commandClass('ada', '2026-09');
        self::assertEquals(new DependentSeatTaken('acme@2026-09', 'ada'), $mediator->send($command));
        self::assertCount(1, $container->get(InMemoryEventStore::class)->load(StreamQuery::forStream(new DomainIdentifier('companyMonth', 'acme@2026-09')))->events);
        if (TakeDependentSeatForCompany::class !== $commandClass) {
            $this->expectException(InvariantViolation::class);
            $mediator->send(new $commandClass('grace', '2026-09'));
        }
    }

    public static function eventBindings(): iterable
    {
        yield 'explicit source method' => [TakeDependentSeat::class];
        yield 'conventional source method' => [TakeDependentSeatByConvention::class];
        yield 'parameterless method' => [TakeDependentSeatForCompany::class];
    }

    public function test_rounds_follow_dependencies_even_when_handler_parameters_are_reversed(): void
    {
        $container = $this->storedApplication(ChargeDependentMinutes::class, GetDependentUsage::class)->boot();
        $database = $container->get(InMemoryRowDatabase::class);
        $database->seedRow('dependent_members', 'member_id', ['member_id' => 'ada', 'company_id' => 'acme']);
        $database->seedRow('dependent_companies', 'company_id', ['company_id' => 'acme', 'monthly_minutes' => 120]);
        $mediator = $container->get('test.mediator');
        self::assertNull($mediator->send(new GetDependentUsage('ada', '2026-09')));
        self::assertEquals(new DependentUsageRow('acme@2026-09', 60), $mediator->send(new ChargeDependentMinutes('ada', '2026-09', 60)));
        self::assertEquals(new DependentUsageRow('acme@2026-09', 90), $mediator->send(new ChargeDependentMinutes('ada', '2026-09', 30)));
        self::assertSame(90, $mediator->send(new GetDependentUsage('ada', '2026-09')));
        try {
            $mediator->send(new ChargeDependentMinutes('ada', '2026-09', 60));
            self::fail('The decision must use the previously buffered dependent state.');
        } catch (InvariantViolation $exception) {
            self::assertEquals([new Violation('quota.exceeded', 'acme@2026-09 would use 150 minutes.')], $exception->violations);
        }
    }

    #[DataProvider('missingSources')]
    public function test_missing_sources_skip_dependent_loads_and_propagate_null(string $memberId, array $codes): void
    {
        $container = $this->storedApplication(ChargeDependentMinutes::class, GetDependentUsage::class)->boot();
        $container->get(InMemoryRowDatabase::class)->seedRow('dependent_members', 'member_id', ['member_id' => 'orphan', 'company_id' => 'unknown']);
        $mediator = $container->get('test.mediator');
        self::assertSame($codes, array_map(static fn (Violation $v): string => $v->code, $mediator->send(new ChargeDependentMinutes($memberId, '2026-09', 10), [DecisionMode::CONTEXT_KEY => DecisionMode::CHECK])));
        self::assertNull($mediator->send(new GetDependentUsage($memberId, '2026-09')));
        self::assertNull($container->get('test.state_store')->load(DependentUsageRow::class, 'unknown@2026-09'));
    }

    public static function missingSources(): iterable
    {
        yield 'first round missing' => ['nobody', ['member.missing', 'company.missing', 'usage.missing']];
        yield 'second round missing' => ['orphan', ['company.missing', 'usage.missing']];
    }
}
