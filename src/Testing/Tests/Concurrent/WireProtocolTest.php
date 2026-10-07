<?php

declare(strict_types=1);

namespace Ultra\Testing\Tests\Concurrent;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Ultra\Testing\Concurrent\Exception\ProtocolException;
use Ultra\Testing\Concurrent\Protocol\AbortMessage;
use Ultra\Testing\Concurrent\Protocol\CheckpointMessage;
use Ultra\Testing\Concurrent\Protocol\Codec;
use Ultra\Testing\Concurrent\Protocol\ExceptionMessage;
use Ultra\Testing\Concurrent\Protocol\InitMessage;
use Ultra\Testing\Concurrent\Protocol\LogMessage;
use Ultra\Testing\Concurrent\Protocol\Message;
use Ultra\Testing\Concurrent\Protocol\ReadyMessage;
use Ultra\Testing\Concurrent\Protocol\ReleaseMessage;
use Ultra\Testing\Concurrent\Protocol\ResultMessage;

/** @internal */
#[CoversClass(Codec::class)]
final class WireProtocolTest extends TestCase
{
    /** @return iterable<string, array{Message, array<string, mixed>}> */
    public static function messages(): iterable
    {
        yield 'ready' => [new ReadyMessage(), ['type' => 'ready']];
        yield 'checkpoint without payload' => [
            new CheckpointMessage('loaded'),
            ['type' => 'checkpoint', 'name' => 'loaded', 'payload' => null],
        ];
        yield 'checkpoint with structured text' => [
            new CheckpointMessage('step', ['version' => 3, 'ids' => [1, 2], 'text' => "héllo 🎉\nnext"]),
            [
                'type' => 'checkpoint',
                'name' => 'step',
                'payload' => ['version' => 3, 'ids' => [1, 2], 'text' => "héllo 🎉\nnext"],
            ],
        ];
        yield 'log' => [
            new LogMessage('warn', 'something odd'),
            ['type' => 'log', 'level' => 'warn', 'message' => 'something odd'],
        ];
        yield 'result' => [new ResultMessage('opaque-result'), ['type' => 'result', 'value' => 'opaque-result']];
        yield 'exception with serialized object' => [
            new ExceptionMessage(\RuntimeException::class, 'boom', '#0 ...', 'opaque-exception'),
            [
                'type' => 'exception',
                'class' => \RuntimeException::class,
                'message' => 'boom',
                'trace' => '#0 ...',
                'serialized' => 'opaque-exception',
            ],
        ];
        yield 'exception description' => [
            new ExceptionMessage(\LogicException::class, 'not serializable', ''),
            ['type' => 'exception', 'class' => \LogicException::class, 'message' => 'not serializable', 'trace' => ''],
        ];
        yield 'init' => [new InitMessage('opaque-closure'), ['type' => 'init', 'closure' => 'opaque-closure']];
        yield 'release with injection' => [
            new ReleaseMessage('loaded', ['extra' => 'data']),
            ['type' => 'release', 'name' => 'loaded', 'inject' => ['extra' => 'data']],
        ];
        yield 'release without injection' => [
            new ReleaseMessage('done'),
            ['type' => 'release', 'name' => 'done', 'inject' => null],
        ];
        yield 'abort' => [new AbortMessage(), ['type' => 'abort']];
    }

    /** @param array<string, mixed> $wire */
    #[DataProvider('messages')]
    public function test_each_message_has_one_json_frame_and_preserves_its_fields(Message $message, array $wire): void
    {
        $line = Codec::encode($message);

        static::assertSame($wire, json_decode($line, true, flags: JSON_THROW_ON_ERROR));
        static::assertSame(1, substr_count($line, "\n"));
        static::assertStringEndsWith("\n", $line);
        static::assertEquals($message, Codec::decode('  ' . json_encode($wire, JSON_THROW_ON_ERROR) . "  \n"));
        static::assertSame($wire['type'], $message->type());
    }

    /** @return iterable<string, array{string, string}> */
    public static function invalidFrames(): iterable
    {
        yield 'invalid json' => ['not json', 'JSON decode failed'];
        yield 'missing type' => ['{"name":"foo"}', 'Missing or invalid "type"'];
        yield 'scalar' => ['"ready"', 'Missing or invalid "type"'];
        yield 'non string type' => ['{"type":1}', 'Missing or invalid "type"'];
        yield 'null type' => ['{"type":null}', 'Missing or invalid "type"'];
        yield 'unknown type' => ['{"type":"bogus"}', 'Unknown message type "bogus"'];
    }

    #[DataProvider('invalidFrames')]
    public function test_invalid_frames_fail_with_protocol_diagnostics(string $line, string $message): void
    {
        $this->expectException(ProtocolException::class);
        $this->expectExceptionMessage($message);
        Codec::decode($line);
    }

    public function test_unknown_messages_cannot_be_encoded(): void
    {
        $this->expectException(ProtocolException::class);
        $this->expectExceptionMessage('Cannot encode unknown message class');
        Codec::encode(new class extends Message {
            public function type(): string
            {
                return 'unsupported';
            }
        });
    }
}
