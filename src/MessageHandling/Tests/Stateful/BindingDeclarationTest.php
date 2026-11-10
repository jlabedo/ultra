<?php

declare(strict_types=1);

namespace Ultra\StatefulMessageHandling\Tests;

use PHPUnit\Framework\Attributes\DataProvider;
use Ultra\StatefulMessageHandling\Tests\Support\DecisionTestCase;

final class BindingDeclarationTest extends DecisionTestCase
{
    #[DataProvider('invalidBindings')]
    public function test_invalid_bindings_fail_during_container_compilation(
        string $command,
        string $message,
        bool $stored = false,
    ): void {
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage($message);
        $application = $stored ? $this->storedApplication($command) : $this->dependentApplication($command);
        $application->withService(new \DateTimeImmutable(), \DateTimeImmutable::class)->boot();
    }

    #[DataProvider('invalidMultiTagBindings')]
    public function test_invalid_multi_tag_bindings_fail_during_compilation(string $command, string $message): void
    {
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage($message);
        $this->multiTagApplication($command, UntaggedDecisionState::class)->boot();
    }

    public static function invalidMultiTagBindings(): iterable
    {
        yield 'both property names even with equal values' => [
            ClashingTagProperties::class,
            'ambiguous conventional bindings (property "courseId", property "course")',
        ];
        yield 'bare property and suffixed method' => [
            ClashingTagPropertyAndMethod::class,
            'ambiguous conventional bindings (property "course", method "courseId()")',
        ];
        yield 'all three candidates' => [
            ClashingTagCandidates::class,
            'ambiguous conventional bindings (property "courseId", property "course", method "courseId()")',
        ];
        yield 'ambiguous override' => [MultiTagBindingWithoutTag::class, 'requires an explicit tag in #[BindTo]'];
        yield 'unknown tag' => [MultiTagBindingUnknownTag::class, 'Unknown tag "faculty" in #[BindTo]'];
        yield 'alias cannot identify state' => [
            MultiTagBindingAliasTag::class,
            'Unknown tag "previousCourse" in #[BindTo]',
        ];
        yield 'duplicate override' => [MultiTagBindingDuplicateTag::class, 'Duplicate #[BindTo] for tag "course"'];
        yield 'missing second tag' => [
            MultiTagBindingMissingValue::class,
            'Expected property "studentId", property "student", method "studentId()"',
        ];
        yield 'cycle through second tag' => [
            MultiTagBindingCycle::class,
            'cyclic method binding dependency: pair -> member -> pair',
        ];
        yield 'no identity tags' => [
            InspectUntaggedDecisionState::class,
            'must declare at least one event-sourced tag',
        ];
    }

    public static function invalidBindings(): iterable
    {
        yield 'ambiguous convention' => [
            DependentBindingConventionClashCommand::class,
            'ambiguous conventional bindings (property "companyMonthId", method "companyMonthId()")',
        ];
        yield 'missing convention' => [
            DependentBindingConventionMissingCommand::class,
            'Expected property "companyMonthId", property "companyMonth", method "companyMonthId()"',
        ];
        yield 'unknown source name' => [
            DependentBindingUnknownParameterCommand::class,
            'parameter "$someone" of '
                . DependentBindingUnknownParameterCommand::class
                . '::companyMonthId() does not match any state parameter',
        ];
        yield 'service cannot be a source' => [
            DependentBindingNonStateParameterCommand::class,
            'parameter "$clock" of '
                . DependentBindingNonStateParameterCommand::class
                . '::companyMonthId() does not match any state parameter',
        ];
        yield 'source type mismatch' => [
            DependentBindingTypeMismatchCommand::class,
            'parameter "$member" of '
                . DependentBindingTypeMismatchCommand::class
                . '::companyMonthId() must be typed '
                . DependentMemberState::class,
        ];
        yield 'dependency cycle' => [
            DependentBindingCycleCommand::class,
            'cyclic method binding dependency: member -> seats -> member',
        ];
        yield 'missing method' => [DependentBindingMissingMethodCommand::class, 'has no method "companyMonthId"'];
        yield 'two binding targets' => [
            DependentBindingBothTargetsCommand::class,
            '#[BindTo] requires exactly one of a property path or a method.',
        ];
        yield 'no binding target' => [
            DependentBindingNoTargetCommand::class,
            '#[BindTo] requires exactly one of a property path or a method.',
        ];
        yield 'nonnullable dependent of nullable source' => [
            DependentOnNullableSourceCommand::class,
            'state parameter "company" depends on the nullable state parameter "member"',
            true,
        ];
    }
}
