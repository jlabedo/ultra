<?php

declare(strict_types=1);

namespace Ultra\Events\Tests;

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

test('compiled registry returns domain identifier values for public and non-public properties', function () {
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

    expect($registry->getDomainIdentifiers(new PublicTaggedEvent('course-public')))
        ->toBe(['course' => 'course-public'])
        ->and($registry->getDomainIdentifiers(new ProtectedTaggedEvent('course-protected')))
        ->toBe(['course' => 'course-protected'])
        ->and($registry->getDomainIdentifiers(new PrivateTaggedEvent('course-private')))
        ->toBe(['course' => 'course-private'])
    ;
});
