<?php

declare(strict_types=1);

namespace Ultra\EventStore\Tests;

use PHPUnit\Framework\Attributes\CoversNamespace;
use PHPUnit\Framework\Attributes\DataProvider;
use Ultra\EventSourcedState\Exception\AmbiguousTagError;
use Ultra\EventSourcedState\Exception\DuplicateEventSourcingHandlerError;
use Ultra\EventSourcedState\Exception\InvalidMatchTagError;
use Ultra\EventSourcedState\Exception\InvalidTagAliasError;
use Ultra\EventSourcedState\Exception\MissingEventSourcingHandlerError;
use Ultra\EventSourcedState\Exception\MissingTypeHintError;
use Ultra\EventSourcedState\Exception\NoTagOverlapError;
use Ultra\EventSourcedState\Exception\UnnecessaryMatchTagError;
use Ultra\EventSourcedState\Tests\Support\EventSourcedStateFunctionalTestCase;
use Ultra\EventStore\Tests\Support\CompilerAliasListState;
use Ultra\EventStore\Tests\Support\CompilerAliasOfUnknownTagState;
use Ultra\EventStore\Tests\Support\CompilerAliasShadowingATagState;
use Ultra\EventStore\Tests\Support\CompilerAmbiguousAliasState;
use Ultra\EventStore\Tests\Support\CompilerAmbiguousEvent;
use Ultra\EventStore\Tests\Support\CompilerAmbiguousState;
use Ultra\EventStore\Tests\Support\CompilerDuplicateHandlerState;
use Ultra\EventStore\Tests\Support\CompilerInvalidMatchTagState;
use Ultra\EventStore\Tests\Support\CompilerMissingHandlerAttributeState;
use Ultra\EventStore\Tests\Support\CompilerMissingTypeHintState;
use Ultra\EventStore\Tests\Support\CompilerNoOverlapEvent;
use Ultra\EventStore\Tests\Support\CompilerNoOverlapState;
use Ultra\EventStore\Tests\Support\CompilerRoomDayBookedEvent;
use Ultra\EventStore\Tests\Support\CompilerRoomDayMovedEvent;
use Ultra\EventStore\Tests\Support\CompilerSingleTagEvent;
use Ultra\EventStore\Tests\Support\CompilerUnnecessaryMatchTagState;

require_once __DIR__ . '/Support/StateDeclarationFixtures.php';

/** @internal */
#[CoversNamespace('Ultra\EventSourcedState')]
#[CoversNamespace('Ultra\EventSourcedState_Compiler')]
final class HandlerDeclarationTest extends EventSourcedStateFunctionalTestCase
{
    /**
     * @param class-string $state
     * @param class-string<\Throwable> $exception
     */
    #[DataProvider('invalidDeclarations')]
    public function test_invalid_handler_or_alias_declarations_fail_during_compilation(
        string $state,
        string $exception,
        ?string $message,
    ): void {
        $this->expectException($exception);
        if (null !== $message) {
            $this->expectExceptionMessage($message);
        }
        $this
            ->eventSourcedStateUltraLight([
                CompilerNoOverlapEvent::class,
                CompilerAmbiguousEvent::class,
                CompilerSingleTagEvent::class,
                CompilerRoomDayBookedEvent::class,
                CompilerRoomDayMovedEvent::class,
            ])
            ->withClass($state)
            ->boot();
    }

    /** @return iterable<string, array{class-string, class-string<\Throwable>, ?string}> */
    public static function invalidDeclarations(): iterable
    {
        yield 'no event tag overlap' => [CompilerNoOverlapState::class, NoTagOverlapError::class, null];
        yield 'ambiguous event tags' => [CompilerAmbiguousState::class, AmbiguousTagError::class, null];
        yield 'unnecessary match tag' => [
            CompilerUnnecessaryMatchTagState::class,
            UnnecessaryMatchTagError::class,
            null,
        ];
        yield 'invalid match tag' => [CompilerInvalidMatchTagState::class, InvalidMatchTagError::class, null];
        yield 'match tag without handler' => [
            CompilerMissingHandlerAttributeState::class,
            MissingEventSourcingHandlerError::class,
            null,
        ];
        yield 'non-class event parameter' => [CompilerMissingTypeHintState::class, MissingTypeHintError::class, null];
        yield 'duplicate handler' => [
            CompilerDuplicateHandlerState::class,
            DuplicateEventSourcingHandlerError::class,
            null,
        ];
        yield 'alias targets unknown tag' => [
            CompilerAliasOfUnknownTagState::class,
            InvalidTagAliasError::class,
            'alias "previousRoomDay" targets "room", which is not one of its tags [roomDay]',
        ];
        yield 'alias shadows tag' => [
            CompilerAliasShadowingATagState::class,
            InvalidTagAliasError::class,
            'alias "room" is already one of its tags',
        ];
        yield 'alias list' => [
            CompilerAliasListState::class,
            InvalidTagAliasError::class,
            'aliases must map an alias name to a tag name',
        ];
        yield 'ambiguous tag and alias' => [
            CompilerAmbiguousAliasState::class,
            AmbiguousTagError::class,
            'matches tags [roomDay, previousRoomDay]',
        ];
    }
}
