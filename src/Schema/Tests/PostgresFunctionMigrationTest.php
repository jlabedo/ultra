<?php

declare(strict_types=1);

namespace Ultra\Schema\Tests;

use PHPUnit\Framework\Attributes\DataProvider;
use Ultra\Schema\PostgresFunctionDefinition;
use Ultra\Schema\SchemaDefinition;
use Ultra\Schema\SchemaReconciler;
use Ultra\Schema\Tests\Support\MigrationTestCase;

final class PostgresFunctionMigrationTest extends MigrationTestCase
{
    public function test_a_compatible_function_body_change_replaces_the_definition(): void
    {
        $previous = self::schema('SELECT value + 1');
        $target = self::schema('SELECT value + length(\'$ultra_function$\')');

        $reconciler = new SchemaReconciler();
        $this->execute($reconciler->reconcile(new SchemaDefinition([]), $previous));
        self::assertSame(3, (int) $this->connection->fetchOne('SELECT calculate(2)'));

        $this->execute($reconciler->reconcile($previous, $target));
        self::assertSame(18, (int) $this->connection->fetchOne('SELECT calculate(2)'));
        self::assertSame([], $reconciler->reconcile($target, $target));
    }

    #[DataProvider('incompatibleChanges')]
    public function test_signature_changes_require_manual_migrations(SchemaDefinition $target): void
    {
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('requires a manual migration');
        new SchemaReconciler()->reconcile(self::schema('SELECT value'), $target);
    }

    /** @return iterable<string, array{SchemaDefinition}> */
    public static function incompatibleChanges(): iterable
    {
        yield 'renamed function' => [self::schema('SELECT value', name: 'other')];
        yield 'changed argument type' => [self::schema('SELECT value', argumentType: 'bigint')];
        yield 'changed return type' => [self::schema('SELECT value', returnType: 'bigint')];
    }

    public function test_removing_a_function_requires_a_manual_migration(): void
    {
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('Removing functions requires a manual migration');
        new SchemaReconciler()->reconcile(self::schema('SELECT value'), new SchemaDefinition([]));
    }

    private static function schema(
        string $body,
        string $name = 'calculate',
        string $argumentType = 'integer',
        string $returnType = 'integer',
    ): SchemaDefinition {
        return new SchemaDefinition([], functions: [new PostgresFunctionDefinition(
            'calculate',
            $name,
            ['value' => $argumentType],
            $returnType,
            'sql',
            $body,
        )]);
    }
}
