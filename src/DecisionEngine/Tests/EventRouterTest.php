<?php

declare(strict_types=1);

namespace Ultra\DecisionEngine\Tests;

use Ultra\DecisionEngine\Attribute\DomainIdentifier;
use Ultra\DecisionEngine\Engine\EventRouter;
use Ultra\EventBus\CompiledEventRegistry;
use Ultra\MessageHandling\Attribute\Event;
use Ultra\Mapper\UltraNormalizer;

#[Event('decision.parameter_tagged')]
final class ParameterTaggedEvent
{
    public readonly string $courseId;

    public function __construct(
        #[DomainIdentifier('course')]
        string $courseId,
        public readonly string $name,
    ) {
        $this->courseId = $courseId;
    }
}

test('event router reads domain identifiers from constructor parameter attributes', function () {
    $router = new EventRouter(
        new UltraNormalizer(sys_get_temp_dir().'/decision-router-'.bin2hex(random_bytes(4))),
        new CompiledEventRegistry([
            'decision.parameter_tagged' => ParameterTaggedEvent::class,
        ]),
    );

    $appendable = $router->toAppendableEvent(new ParameterTaggedEvent('course-1', 'Math'));

    expect($appendable->eventType)->toBe('decision.parameter_tagged')
        ->and($appendable->domainIds)->toBe(['course' => 'course-1'])
        ->and($appendable->payload)->toBe([
            'courseId' => 'course-1',
            'name' => 'Math',
        ]);
});
