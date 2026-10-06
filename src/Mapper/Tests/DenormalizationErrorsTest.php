<?php

declare(strict_types=1);

namespace Ultra\Mapper\Tests;

use PHPUnit\Framework\Attributes\CoversNamespace;
use PHPUnit\Framework\Attributes\DataProvider;
use Ultra\Mapper\DenormalizationContext;
use Ultra\Mapper\Exception\DenormalizationException;
use Ultra\Mapper\Path;
use Ultra\Mapper\Tests\Fixtures\DeepNest;
use Ultra\Mapper\Tests\Fixtures\CustomerWithRuntimeEmail;
use Ultra\Mapper\Tests\Fixtures\EmailList;
use Ultra\Mapper\Tests\Fixtures\Foo;
use Ultra\Mapper\Tests\Fixtures\MultiUnion;
use Ultra\Mapper\Tests\Fixtures\RuntimeEmailNormalizer;
use Ultra\Mapper\Tests\Fixtures\User;
use Ultra\Mapper\Tests\Support\MapperTestCase;

/** @internal */
#[CoversNamespace('Ultra\Mapper')]
#[CoversNamespace('Ultra\Mapper_Compiler')]
final class DenormalizationErrorsTest extends MapperTestCase
{
    /** @param class-string $type */
    #[DataProvider('invalidRepresentations')]
    public function test_invalid_representations_report_the_type_and_path(string $type, array $data, string $reason, string $path, bool $withPrefix = false): void
    {
        $normalizer = $this->normalizer();
        $normalizer->registerRuntimeNormalizer(new RuntimeEmailNormalizer());
        $context = $withPrefix ? new DenormalizationContext(Path::new()->add('payload')) : null;

        try {
            $normalizer->fromScalar($data, $type, $context);
            self::fail('Expected invalid representation to be rejected.');
        } catch (DenormalizationException $exception) {
            self::assertStringContainsString($reason, $exception->getMessage());
            self::assertStringContainsString($path, $exception->getMessage());
        }
    }

    /** @return iterable<string, array{class-string, array, string, string, bool?: bool}> */
    public static function invalidRepresentations(): iterable
    {
        yield 'scalar type' => [Foo::class, ['id' => '42', 'name' => 'bar'], 'expected int', '[id]'];
        yield 'required property' => [Foo::class, ['name' => 'bar'], 'Missing required property', '[id]'];
        yield 'collection element' => [EmailList::class, ['a@example.test', 123], 'expected string', '[1]'];
        yield 'nested object' => [User::class, [
            'username' => 'bob', 'address' => ['street' => 'Main', 'city' => 'Paris', 'zip' => 75000],
        ], 'expected string', '[address][zip]'];
        yield 'deep object' => [DeepNest::class, [
            'user' => ['username' => 'deep', 'address' => ['street' => 'Galaxy', 'city' => 'Cosmos', 'zip' => 42]],
            'emails' => ['deep@example.test'],
        ], 'expected string', '[user][address][zip]'];
        yield 'union with caller context' => [MultiUnion::class, ['value' => ['invalid']], 'does not match any type', '[payload][value]', true];
        yield 'custom normalizer with caller context' => [CustomerWithRuntimeEmail::class, [
            'name' => 'Ada', 'email' => 'invalid', 'phone' => '123',
        ], 'expected runtime email', '[payload][email]', true];
    }

    public function test_paths_keep_existing_segments_and_render_runtime_variables(): void
    {
        $root = Path::new();
        $path = $root->add('foo', 'bar', 42);

        self::assertSame('', (string) $root);
        self::assertSame('[foo][bar][42]', (string) $path);
        self::assertSame("['foo', 'bar', 42, \$k]", $path->toArrayString(['$k']));
        self::assertSame("'foo', 'bar', 42", $path->toList());
        self::assertSame("['foo']['bar'][42][\$k]", $path->addVariable('$k')->toArrayAccess());
        self::assertSame('$k', (string) Path::var('$k'));
        self::assertSame('$k', (string) Path::var('k'));
    }
}
