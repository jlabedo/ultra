<?php

declare(strict_types=1);

namespace Ultra\EventStore\Tests;

use PHPUnit\Framework\Attributes\CoversNamespace;
use Ultra\EventStore\DomainIdentifier;
use Ultra\EventStore\StreamQuery;
use Ultra\EventStore\Tests\Support\EventStoreTestCase;
use Ultra\EventStore_Postgres\LockStrategy\StreamQueryLockKeys;

/** @internal */
#[CoversNamespace('Ultra\EventStore')]
#[CoversNamespace('Ultra\EventStore_Postgres')]
final class LockNamespaceTest extends EventStoreTestCase
{
    public function test_writes_cover_every_nonempty_identifier_subset_in_typed_and_wildcard_namespaces(): void
    {
        $locks = new StreamQueryLockKeys();
        $writeKeys = $locks->writeKeysForEvent('subscribed', ['student' => 's1', 'faculty' => 'f1', 'course' => 'c1']);
        static::assertCount(14, $writeKeys);
        foreach ([
            ['course' => 'c1'],
            ['student' => 's1'],
            ['faculty' => 'f1'],
            ['course' => 'c1', 'student' => 's1'],
            ['faculty' => 'f1', 'course' => 'c1'],
            ['student' => 's1', 'faculty' => 'f1'],
            ['course' => 'c1', 'student' => 's1', 'faculty' => 'f1'],
        ] as $subset) {
            $identifiers = [];
            foreach ($subset as $dimension => $value) {
                $identifiers[] = new DomainIdentifier($dimension, $value);
            }
            $query = StreamQuery::forStream(...$identifiers);
            $typed = $locks->readKeys($query->ofTypes('subscribed'));
            $untyped = $locks->readKeys($query);
            static::assertCount(1, $typed);
            static::assertCount(1, $untyped);
            static::assertNotSame($typed, $untyped);
            static::assertContains($typed[0], $writeKeys);
            static::assertContains($untyped[0], $writeKeys);
            static::assertNotContains($locks->readKeys($query->ofTypes('other.type'))[0], $writeKeys);
        }
    }

    public function test_overlapping_branches_share_keys_regardless_of_identifier_order(): void
    {
        $locks = new StreamQueryLockKeys();
        $branch = StreamQuery::forStream(
            new DomainIdentifier('course', 'c1'),
            new DomainIdentifier('student', 's1'),
        )->ofTypes('subscribed');
        $reversed = StreamQuery::forStream(
            new DomainIdentifier('student', 's1'),
            new DomainIdentifier('course', 'c1'),
        )->ofTypes('subscribed');

        static::assertSame(
            $locks->buildKey('subscribed', ['course' => 'c1', 'student' => 's1']),
            $locks->buildKey('subscribed', ['student' => 's1', 'course' => 'c1']),
        );
        static::assertSame($locks->readKeys($branch), $locks->readKeys(StreamQuery::anyOf($branch, $reversed)));
        static::assertCount(2, $locks->readKeys($branch->ofTypes('subscribed', 'unsubscribed')));
    }
}
