<?php

declare(strict_types=1);

namespace Ultra\DecisionEngine\Tests\Example\Tests;

use Ultra\DecisionEngine\Tests\Example\Event\StudentSubscribed;
use Ultra\DecisionEngine\Tests\Example\Event\StudentUnsubscribed;
use Ultra\DecisionEngine\Tests\Example\State\StudentSubscriptionState;

test('handlers correctly track subscriptions', function () {
    $state = new StudentSubscriptionState();
    $state->onStudentSubscribed(new StudentSubscribed('math-101', 'alice'));
    $state->onStudentSubscribed(new StudentSubscribed('physics-101', 'alice'));

    expect($state->isSubscribedTo('math-101'))->toBeTrue();
    expect($state->isSubscribedTo('physics-101'))->toBeTrue();
    expect($state->isSubscribedTo('bio-101'))->toBeFalse();
});

test('isSubscribedTo returns false after unsubscribe', function () {
    $state = new StudentSubscriptionState();
    $state->onStudentSubscribed(new StudentSubscribed('math-101', 'alice'));
    $state->onStudentUnsubscribed(new StudentUnsubscribed('math-101', 'alice'));

    expect($state->isSubscribedTo('math-101'))->toBeFalse();
});

test('empty events returns state with no subscriptions', function () {
    $state = new StudentSubscriptionState();

    expect($state->isSubscribedTo('math-101'))->toBeFalse();
});
