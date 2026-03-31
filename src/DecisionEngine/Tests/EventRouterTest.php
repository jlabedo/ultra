<?php

declare(strict_types=1);

namespace Ultra\DecisionEngine\Tests;

use Ultra\DecisionEngine\Engine\EventRouter;
use Ultra\Events\Attribute\DomainIdentifier;
use Ultra\Events\Attribute\Event;
use Ultra\Events\CompiledEventRegistry;
use Ultra\Mapper\UltraNormalizer;

#[Event('decision.parameter_tagged')]
final class PropertyTaggedEvent
{
    public function __construct(
        #[DomainIdentifier('course')]
        public readonly string $courseId,
        public readonly string $name,
    ) {}
}

test('event router reads domain identifiers from property attributes', function () {
    $router = new EventRouter(
        new UltraNormalizer(sys_get_temp_dir().'/decision-router-'.bin2hex(random_bytes(4))),
        new CompiledEventRegistry([
            'decision.parameter_tagged' => PropertyTaggedEvent::class,
        ], [
            PropertyTaggedEvent::class => ['course' => 'courseId'],
        ]),
    );

    $appendable = $router->toAppendableEvent(new PropertyTaggedEvent('course-1', 'Math'));

    expect($appendable->eventType)->toBe('decision.parameter_tagged')
        ->and($appendable->domainIds)->toBe(['course' => 'course-1'])
        ->and($appendable->payload)->toBe([
            'courseId' => 'course-1',
            'name' => 'Math',
        ]);
});
