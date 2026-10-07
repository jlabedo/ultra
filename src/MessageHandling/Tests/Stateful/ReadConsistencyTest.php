<?php

declare(strict_types=1);

namespace Ultra\StatefulMessageHandling\Tests;

use PHPUnit\Framework\Attributes\DataProvider;
use Ultra\EventStore\DomainIdentifier;
use Ultra\EventStore\Exception\ConcurrencyException as EventConcurrencyException;
use Ultra\EventStore\InMemoryEventStore;
use Ultra\EventStore\StreamQuery;
use Ultra\MappedTable\InMemoryRowDatabase;
use Ultra\StatefulMessageHandling\DecisionMode;
use Ultra\StatefulMessageHandling\Tests\Support\DecisionTestCase;
use Ultra\UnitOfWork\Exception\ConcurrencyException;

final class ReadConsistencyTest extends DecisionTestCase
{
    public function test_all_event_loading_rounds_guard_the_final_append(): void
    {
        $container = $this->dependentApplication(
            JoinDependentCompany::class,
            TakeDependentSeatWhileMemberMoves::class,
        )->boot();
        $mediator = $container->get('test.mediator');
        $mediator->send(new JoinDependentCompany('ada', 'acme'));
        try {
            $mediator->send(new TakeDependentSeatWhileMemberMoves('ada', '2026-09'));
            static::fail('A change to the source state must invalidate the dependent decision.');
        } catch (EventConcurrencyException) {
            static::assertSame(
                [],
                $container->get(InMemoryEventStore::class)->load(StreamQuery::forStream(new DomainIdentifier(
                    'companyMonth',
                    'acme@2026-09',
                )))->events,
            );
        }
    }

    public function test_alias_matched_events_are_part_of_the_decision_append_condition(): void
    {
        $container = $this->application([
            AliasDayDesksState::class,
            BookAliasDeskWhileAnotherMovesAway::class,
        ], [AliasDeskBooked::class, AliasDeskMoved::class])->boot();
        try {
            $container->get('test.mediator')->send(new BookAliasDeskWhileAnotherMovesAway('monday', 'desk'));
            static::fail('An event selected through the alias must invalidate the decision.');
        } catch (EventConcurrencyException) {
            static::assertSame(
                [],
                $container->get(InMemoryEventStore::class)->load(StreamQuery::forStream(new DomainIdentifier(
                    'day',
                    'monday',
                ))->ofTypes('alias_decision.desk_booked'))->events,
            );
        }
    }

    #[DataProvider('readObservations')]
    public function test_stored_reads_are_expected_without_returned_saves_including_during_check(
        bool $exists,
        bool $check,
    ): void {
        $container = $this->inspectedApplication('stored')->boot();
        $database = $container->get(InMemoryRowDatabase::class);
        if ($exists) {
            $database->seedRow('decision_orders', 'order_id', ['order_id' => 'order', 'status' => 'paid']);
        }
        $container->get('test.mediator')->send(
            new InspectDecisionOrder('order', null),
            $check ? [DecisionMode::CONTEXT_KEY => DecisionMode::CHECK] : [],
        );
        $database->seedRow('decision_orders', 'order_id', ['order_id' => 'order', 'status' => 'concurrent']);
        $this->expectException(ConcurrencyException::class);
        $this->flush($container);
    }

    public static function readObservations(): iterable
    {
        yield 'loaded, normal' => [true, false];
        yield 'missing, normal' => [false, false];
        yield 'loaded, CHECK' => [true, true];
        yield 'missing, CHECK' => [false, true];
    }

    #[DataProvider('sourceRounds')]
    public function test_loaded_stored_sources_guard_flush_even_when_only_the_dependent_state_is_saved(
        string $table,
        string $column,
        array $concurrent,
    ): void {
        $container = $this->storedApplication(ChargeDependentMinutes::class)->boot();
        $database = $container->get(InMemoryRowDatabase::class);
        $database->seedRow('dependent_members', 'member_id', ['member_id' => 'ada', 'company_id' => 'acme']);
        $database->seedRow('dependent_companies', 'company_id', ['company_id' => 'acme', 'monthly_minutes' => 120]);
        $container->get('test.mediator')->send(new ChargeDependentMinutes('ada', '2026-09', 10));
        $database->seedRow($table, $column, $concurrent);
        try {
            $this->flush($container);
            static::fail('Every source read must remain protected until flush.');
        } catch (ConcurrencyException) {
            static::assertFalse($database->row(
                'dependent_usages',
                'company_month_id',
                'acme@2026-09',
                ['used_minutes'],
            ));
        }
    }

    public static function sourceRounds(): iterable
    {
        yield 'member round' => ['dependent_members', 'member_id', ['member_id' => 'ada', 'company_id' => 'globex']];
        yield 'company round' => [
            'dependent_companies',
            'company_id',
            ['company_id' => 'acme', 'monthly_minutes' => 5],
        ];
    }
}
