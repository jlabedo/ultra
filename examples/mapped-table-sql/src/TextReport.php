<?php

declare(strict_types=1);

namespace MappedTableSqlExample;

use Doctrine\DBAL\ParameterType;
use Ultra\MappedTable\MappedKey;

/** SQL stays parameterized; values and driver types are printed separately. */
final class TextReport
{
    public static function render(Inspection $inspection): string
    {
        $lines = ['Table: ' . $inspection->tableName, '', 'Pending plan'];
        $plan = $inspection->plan;
        if (null === $plan) {
            $lines[] = '  Unavailable: setup failed before the scenario ran.';
        } else {
            $lines[] = sprintf('  %d row operation(s)', count($plan->rowUpdates));
            foreach ($plan->rowUpdates as $row) {
                $lines[] = sprintf(
                    '  %s %s (presence: %s)',
                    strtoupper($row->mode),
                    self::value($row->key->values),
                    $row->expectedPresence ?? 'unspecified',
                );
                $lines[] = '    Expect: ' . self::value($row->expectedColumns);
                $lines[] = '    Set: ' . self::value($row->setColumns);
            }
            $keys = static fn(array $values): array => array_map(
                static fn(MappedKey $key): array => $key->values,
                $values,
            );
            $lines[] =
                '  Expected row keys: '
                . (null === $plan->expectedRowKeys ? 'none' : self::value($keys($plan->expectedRowKeys)));
            $lines[] = '  Ignored row keys: ' . self::value($keys($plan->ignoredRowKeys));
            $lines[] =
                '  Retained row keys: '
                . (null === $plan->retainedRowKeys ? 'unchanged' : self::value($keys($plan->retainedRowKeys)));
        }

        $lines[] = '';
        $lines[] = 'Driver trace (chronological; setup and cleanup are separate from the scenario)';
        $scenarioSqlCalls = 0;
        $scenarioDuration = 0;
        foreach ($inspection->entries as $index => $entry) {
            $lines[] = sprintf(
                '  #%03d [%s] %s %.3f ms%s',
                $index + 1,
                $entry->phase,
                strtoupper($entry->operation),
                $entry->durationMilliseconds,
                null === $entry->errorClass ? '' : ' FAILED',
            );
            if (null !== $entry->sql) {
                $lines[] = '    ' . $entry->sql;
            }
            if ([] !== $entry->parameters) {
                $lines[] = '    Parameters: ' . self::value($entry->parameters);
                $lines[] =
                    '    Types: '
                    . self::value(array_map(static fn(ParameterType $type): string => $type->name, $entry->types));
            }
            if (null !== $entry->errorClass) {
                $lines[] = '    ' . $entry->errorClass . ': ' . $entry->errorMessage;
            }
            if ('scenario' === $entry->phase) {
                $scenarioDuration += $entry->durationMilliseconds;
                if (null !== $entry->sql && 'prepare' !== $entry->operation) {
                    ++$scenarioSqlCalls;
                }
            }
        }
        $lines[] = '';
        $lines[] = sprintf(
            'Scenario: %d SQL execution call(s), %.3f ms in recorded driver calls.',
            $scenarioSqlCalls,
            $scenarioDuration,
        );
        $lines[] = 'Durations exclude connection setup, successful preparation and result fetching; they include lock waits.';
        $lines[] = 'Result: ' . ($inspection->succeeded() ? 'OK' : 'FAILED');
        if (null !== $inspection->error) {
            $lines[] = $inspection->error::class . ': ' . $inspection->error->getMessage();
        }
        if (null !== $inspection->cleanupError) {
            $lines[] =
                'Cleanup failed: ' . $inspection->cleanupError::class . ': ' . $inspection->cleanupError->getMessage();
        }

        return implode(PHP_EOL, $lines) . PHP_EOL;
    }

    private static function value(mixed $value): string
    {
        return (string) json_encode(
            $value,
            JSON_UNESCAPED_UNICODE
            | JSON_UNESCAPED_SLASHES
            | JSON_INVALID_UTF8_SUBSTITUTE
            | JSON_PARTIAL_OUTPUT_ON_ERROR,
        );
    }
}
