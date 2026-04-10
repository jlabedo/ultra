<?php

declare(strict_types=1);

namespace Ultra\Events\Tests;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Ultra\Events\Attribute\DomainIdentifier;
use Ultra\Events\Attribute\Event;
use Ultra\Events\CompiledEventRegistry;

#[Event('events.public_tagged')]
final readonly class PublicTaggedEvent
{
    public function __construct(
        #[DomainIdentifier('course')]
        public string $courseId,
    ) {}
}

#[Event('events.protected_tagged')]
final class ProtectedTaggedEvent
{
    #[DomainIdentifier('course')]
    private string $courseId;

    public function __construct(string $courseId)
    {
        $this->courseId = $courseId;
    }
}

#[Event('events.private_tagged')]
final class PrivateTaggedEvent
{
    #[DomainIdentifier('course')]
    private string $courseId;

    public function __construct(string $courseId)
    {
        $this->courseId = $courseId;
    }
}

/**
 * @internal
 */
#[CoversClass(CompiledEventRegistry::class)]
final class CompiledEventRegistryTest extends TestCase
{
    public function test_it_returns_domain_identifier_values_for_public_and_non_public_properties(): void
    {
        $registry = new CompiledEventRegistry([
            'events.public_tagged' => PublicTaggedEvent::class,
            'events.protected_tagged' => ProtectedTaggedEvent::class,
            'events.private_tagged' => PrivateTaggedEvent::class,
        ], [
            PublicTaggedEvent::class => ['course' => 'courseId'],
            ProtectedTaggedEvent::class => ['course' => 'courseId'],
            PrivateTaggedEvent::class => ['course' => 'courseId'],
        ], [
            ProtectedTaggedEvent::class => true,
            PrivateTaggedEvent::class => true,
        ]);

        self::assertSame(
            ['course' => 'course-public'],
            $registry->getDomainIdentifiers(new PublicTaggedEvent('course-public')),
        );
        self::assertSame(
            ['course' => 'course-protected'],
            $registry->getDomainIdentifiers(new ProtectedTaggedEvent('course-protected')),
        );
        self::assertSame(
            ['course' => 'course-private'],
            $registry->getDomainIdentifiers(new PrivateTaggedEvent('course-private')),
        );
    }
}
