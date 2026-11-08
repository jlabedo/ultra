<?php

declare(strict_types=1);

namespace Ultra\EventStore\Tests\Support;

use Ultra\Events\Attribute\DomainIdentifier;
use Ultra\Events\Attribute\Event;

final readonly class MetadataNamedEvent {}

#[Event('course.created')]
final readonly class PublicIdentifiedEvent
{
    public function __construct(
        #[DomainIdentifier('course')]
        public mixed $courseId,
        #[DomainIdentifier('faculty')]
        public string $facultyId = 'f1',
    ) {}
}

#[Event('course.archived')]
final class PrivateIdentifiedEvent
{
    #[DomainIdentifier('course')]
    private mixed $courseId;

    public function __construct(mixed $courseId)
    {
        $this->courseId = $courseId;
    }
}

#[Event('course.renamed')]
final class ProtectedIdentifiedEvent
{
    #[DomainIdentifier('course')]
    protected mixed $courseId;

    public function __construct(
        mixed $courseId,
        #[DomainIdentifier('faculty')]
        public string $facultyId = 'f1',
    ) {
        $this->courseId = $courseId;
    }
}
