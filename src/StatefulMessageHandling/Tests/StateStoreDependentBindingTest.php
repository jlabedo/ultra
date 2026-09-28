<?php

declare(strict_types=1);

namespace Ultra\StatefulMessageHandling\Tests;

use PHPUnit\Framework\TestCase;
use Ultra\EventSourcedState\EventSourcedStateExtension;
use Ultra\Events\EventsExtension;
use Ultra\EventStore\EventStore;
use Ultra\EventStore\InMemoryEventStore;
use Ultra\MappedTable\InMemoryMappedRowSetAdapterFactory;
use Ultra\MappedTable\InMemoryRowDatabase;
use Ultra\MappedTable\MappedRowSetAdapterFactory;
use Ultra\Mapper_Bundle\MapperExtension;
use Ultra\MessageHandling\Attribute\SelfHandler;
use Ultra\MessageHandling\MediatorInterface;
use Ultra\MessageHandling\MessageHandlingExtension;
use Ultra\StatefulMessageHandling\Attribute\BindTo;
use Ultra\StatefulMessageHandling\Attribute\DecisionHandler;
use Ultra\StatefulMessageHandling\Attribute\StateReader;
use Ultra\StatefulMessageHandling\DecisionMode;
use Ultra\StatefulMessageHandling\Exception\InvariantViolation;
use Ultra\StatefulMessageHandling\StatefulMessageHandlingExtension;
use Ultra\StatefulMessageHandling\Violation;
use Ultra\StateStore\Attribute\State;
use Ultra\StateStore\Attribute\StateColumn;
use Ultra\StateStore\Attribute\StateId;
use Ultra\StateStore\StateStore;
use Ultra\StateStore_Bundle\StateStoreExtension;
use Ultra\Testing\UltraLight;
use Ultra\Testing\UltraLightContainer;

#[State(table: 'dependent_members')]
final readonly class DependentMemberRow
{
    public function __construct(
        #[StateId(column: 'member_id')]
        public string $memberId,
        #[StateColumn('company_id')]
        public string $companyId,
    ) {}
}

#[State(table: 'dependent_companies')]
final readonly class DependentCompanyRow
{
    public function __construct(
        #[StateId(column: 'company_id')]
        public string $companyId,
        #[StateColumn('monthly_minutes')]
        public int $monthlyMinutes,
    ) {}
}

#[State(table: 'dependent_usages')]
final readonly class DependentUsageRow
{
    public function __construct(
        #[StateId(column: 'company_month_id')]
        public string $companyMonthId,
        #[StateColumn('used_minutes')]
        public int $usedMinutes,
    ) {}
}

/**
 * Three loading rounds: member, then its company, then the company's usage for the month.
 */
final readonly class ChargeDependentMinutes
{
    public function __construct(
        public string $memberId,
        public string $month,
        public int $minutes,
    ) {}

    public function companyId(DependentMemberRow $member): string
    {
        return $member->companyId;
    }

    public function companyMonthId(DependentCompanyRow $company): string
    {
        return $company->companyId.'@'.$this->month;
    }

    /**
     * Bound by convention: each #[StateId] property name (companyId, companyMonthId) is a message method.
     */
    #[SelfHandler]
    #[DecisionHandler]
    public function decide(
        ?DependentUsageRow $usage,
        ?DependentCompanyRow $company,
        ?DependentMemberRow $member,
    ): DependentUsageRow {
        if (null === $member || null === $company) {
            throw new InvariantViolation(array_values(array_filter([
                null === $member ? new Violation('member.missing', 'Member is missing.') : null,
                null === $company ? new Violation('company.missing', 'Company is missing.') : null,
                null === $usage ? new Violation('usage.missing', 'Usage is missing.') : null,
            ])));
        }

        $used = ($usage?->usedMinutes ?? 0) + $this->minutes;
        if ($used > $company->monthlyMinutes) {
            throw new InvariantViolation([new Violation('quota.exceeded', sprintf('%s would use %d minutes.', $this->companyMonthId($company), $used))]);
        }

        return new DependentUsageRow($this->companyMonthId($company), $used);
    }
}

final readonly class GetDependentUsage
{
    public function __construct(
        public string $memberId,
        public string $month,
    ) {}

    public function companyMonthId(DependentMemberRow $member): string
    {
        return $member->companyId.'@'.$this->month;
    }

    #[SelfHandler]
    #[StateReader]
    public function read(
        ?DependentMemberRow $member,
        #[BindTo(method: 'companyMonthId')]
        ?DependentUsageRow $usage,
    ): ?int {
        return $usage?->usedMinutes;
    }
}

final readonly class DependentOnNullableSourceCommand
{
    public function __construct(public string $memberId) {}

    public function companyId(DependentMemberRow $member): string
    {
        return $member->companyId;
    }

    #[SelfHandler]
    #[DecisionHandler]
    public function decide(
        ?DependentMemberRow $member,
        #[BindTo(method: 'companyId')]
        DependentCompanyRow $company,
    ): array {
        return [];
    }
}

/**
 * @internal
 *
 * @coversNothing
 */
final class StateStoreDependentBindingTest extends TestCase
{
    public function test_dependent_rows_are_loaded_by_computed_ids_and_saved(): void
    {
        $container = $this->ultraLight()->boot();
        $this->seed($container);
        $mediator = $container->get('test.mediator');

        $mediator->send(new ChargeDependentMinutes('ada', '2026-09', 60));
        $mediator->send(new ChargeDependentMinutes('ada', '2026-09', 30));

        self::assertEquals(
            new DependentUsageRow('acme@2026-09', 90),
            $container->get('test.state_store')->load(DependentUsageRow::class, 'acme@2026-09'),
        );

        try {
            $mediator->send(new ChargeDependentMinutes('ada', '2026-09', 60));
            self::fail('The quota should be exceeded.');
        } catch (InvariantViolation $violation) {
            self::assertEquals([new Violation('quota.exceeded', 'acme@2026-09 would use 150 minutes.')], $violation->violations);
        }
    }

    public function test_a_dependent_state_is_null_when_its_source_is_missing(): void
    {
        $container = $this->ultraLight()->boot();
        $this->seed($container);
        $container->get(InMemoryRowDatabase::class)->seedRow('dependent_members', 'member_id', [
            'member_id' => 'orphan',
            'company_id' => 'unknown',
        ]);
        $mediator = $container->get('test.mediator');
        $check = [DecisionMode::CONTEXT_KEY => DecisionMode::CHECK];

        self::assertSame(
            ['member.missing', 'company.missing', 'usage.missing'],
            self::codes($mediator->send(new ChargeDependentMinutes('nobody', '2026-09', 10), $check)),
        );
        self::assertSame(
            ['company.missing', 'usage.missing'],
            self::codes($mediator->send(new ChargeDependentMinutes('orphan', '2026-09', 10), $check)),
        );
    }

    public function test_check_mode_runs_with_dependent_states_without_saving(): void
    {
        $container = $this->ultraLight()->boot();
        $this->seed($container);
        $mediator = $container->get('test.mediator');
        $check = [DecisionMode::CONTEXT_KEY => DecisionMode::CHECK];

        self::assertSame([], $mediator->send(new ChargeDependentMinutes('ada', '2026-09', 60), $check));
        self::assertNull($container->get('test.state_store')->load(DependentUsageRow::class, 'acme@2026-09'));

        $mediator->send(new ChargeDependentMinutes('ada', '2026-09', 100));

        self::assertSame(
            ['quota.exceeded'],
            self::codes($mediator->send(new ChargeDependentMinutes('ada', '2026-09', 60), $check)),
        );
    }

    public function test_state_readers_resolve_dependent_bindings(): void
    {
        $container = $this->ultraLight()->boot();
        $this->seed($container);
        $mediator = $container->get('test.mediator');

        self::assertNull($mediator->send(new GetDependentUsage('ada', '2026-09')));
        self::assertNull($mediator->send(new GetDependentUsage('nobody', '2026-09')));

        $mediator->send(new ChargeDependentMinutes('ada', '2026-09', 45));

        self::assertSame(45, $mediator->send(new GetDependentUsage('ada', '2026-09')));
    }

    public function test_a_state_depending_on_a_nullable_state_must_be_nullable(): void
    {
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('state parameter "company" depends on the nullable state parameter "member"');

        $this->ultraLight(DependentOnNullableSourceCommand::class)->boot();
    }

    private function seed(UltraLightContainer $container): void
    {
        $database = $container->get(InMemoryRowDatabase::class);
        $database->seedRow('dependent_members', 'member_id', ['member_id' => 'ada', 'company_id' => 'acme']);
        $database->seedRow('dependent_companies', 'company_id', ['company_id' => 'acme', 'monthly_minutes' => 120]);
    }

    /**
     * @param list<Violation> $violations
     *
     * @return list<string>
     */
    private static function codes(array $violations): array
    {
        return array_map(static fn (Violation $violation): string => $violation->code, $violations);
    }

    /**
     * @param class-string ...$extraClasses
     *
     * @return UltraLight<UltraLightContainer>
     */
    private function ultraLight(string ...$extraClasses): UltraLight
    {
        $database = new InMemoryRowDatabase();

        return (new UltraLight())
            ->withBuildDir(sys_get_temp_dir().'/ultra_state_store_dependent_binding_tests_'.uniqid())
            ->withBundle(
                new MessageHandlingExtension(),
                new MapperExtension(),
                new EventsExtension(),
                new EventSourcedStateExtension(),
                new StateStoreExtension(),
                new StatefulMessageHandlingExtension(),
            )
            ->withService(new InMemoryEventStore(), InMemoryEventStore::class)
            ->withAlias(EventStore::class, InMemoryEventStore::class)
            ->withService($database, InMemoryRowDatabase::class)
            ->withService(new InMemoryMappedRowSetAdapterFactory($database), InMemoryMappedRowSetAdapterFactory::class)
            ->withAlias(MappedRowSetAdapterFactory::class, InMemoryMappedRowSetAdapterFactory::class)
            ->withAlias('test.mediator', MediatorInterface::class)
            ->withAlias('test.state_store', StateStore::class)
            ->withClass(
                DependentMemberRow::class,
                DependentCompanyRow::class,
                DependentUsageRow::class,
                ...([] === $extraClasses ? [ChargeDependentMinutes::class, GetDependentUsage::class] : $extraClasses),
            )
        ;
    }
}
