<?php

declare(strict_types=1);

namespace Ultra\EventBus_Compiler\Tests;

use Ultra\MessageHandling\Attribute\Event;

#[Event('event-bus.order-created')]
final readonly class TestOrderCreated extends AbstractTestOrderEvent {}
