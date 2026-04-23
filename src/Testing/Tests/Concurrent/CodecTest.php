<?php

declare(strict_types=1);

namespace Ultra\Testing\Tests\Concurrent;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Ultra\Testing\Concurrent\Exception\ProtocolException;
use Ultra\Testing\Concurrent\Protocol\AbortMessage;
use Ultra\Testing\Concurrent\Protocol\CheckpointMessage;
use Ultra\Testing\Concurrent\Protocol\Codec;
use Ultra\Testing\Concurrent\Protocol\ExceptionMessage;
use Ultra\Testing\Concurrent\Protocol\InitMessage;
use Ultra\Testing\Concurrent\Protocol\LogMessage;
use Ultra\Testing\Concurrent\Protocol\ReadyMessage;
use Ultra\Testing\Concurrent\Protocol\ReleaseMessage;
use Ultra\Testing\Concurrent\Protocol\ResultMessage;

/**
 * @internal
 */
#[CoversClass(Codec::class)]
final class CodecTest extends TestCase
{
    public function test_round_trip_ready_message(): void
    {
        $line = Codec::encode(new ReadyMessage());
        $msg = Codec::decode($line);

        self::assertInstanceOf(ReadyMessage::class, $msg);
        self::assertSame('ready', $msg->type());
        self::assertStringEndsWith("\n", $line);
    }

    public function test_round_trip_checkpoint_with_null_payload(): void
    {
        $msg = Codec::decode(Codec::encode(new CheckpointMessage('loaded')));

        self::assertInstanceOf(CheckpointMessage::class, $msg);
        self::assertSame('loaded', $msg->name);
        self::assertNull($msg->payload);
    }

    public function test_round_trip_checkpoint_with_array_payload(): void
    {
        $payload = ['version' => 3, 'ids' => [1, 2, 3]];
        $msg = Codec::decode(Codec::encode(new CheckpointMessage('step', $payload)));

        self::assertInstanceOf(CheckpointMessage::class, $msg);
        self::assertSame('step', $msg->name);
        self::assertSame($payload, $msg->payload);
    }

    public function test_round_trip_checkpoint_with_unicode_payload(): void
    {
        $payload = ['text' => 'héllo wörld 🎉'];
        $msg = Codec::decode(Codec::encode(new CheckpointMessage('unicode', $payload)));

        self::assertInstanceOf(CheckpointMessage::class, $msg);
        self::assertSame($payload, $msg->payload);
    }

    public function test_round_trip_log_message(): void
    {
        $msg = Codec::decode(Codec::encode(new LogMessage('warn', 'something odd')));

        self::assertInstanceOf(LogMessage::class, $msg);
        self::assertSame('warn', $msg->level);
        self::assertSame('something odd', $msg->message);
    }

    public function test_round_trip_result_message(): void
    {
        $value = base64_encode(serialize(['ok' => true]));
        $msg = Codec::decode(Codec::encode(new ResultMessage($value)));

        self::assertInstanceOf(ResultMessage::class, $msg);
        self::assertSame($value, $msg->value);
    }

    public function test_round_trip_exception_message_with_serialized(): void
    {
        $serialized = base64_encode(serialize(new \RuntimeException('boom')));
        $msg = Codec::decode(Codec::encode(new ExceptionMessage(
            class: \RuntimeException::class,
            message: 'boom',
            trace: '#0 ...',
            serialized: $serialized,
        )));

        self::assertInstanceOf(ExceptionMessage::class, $msg);
        self::assertSame(\RuntimeException::class, $msg->class);
        self::assertSame('boom', $msg->message);
        self::assertSame('#0 ...', $msg->trace);
        self::assertSame($serialized, $msg->serialized);
    }

    public function test_round_trip_exception_message_without_serialized(): void
    {
        $msg = Codec::decode(Codec::encode(new ExceptionMessage(
            class: \LogicException::class,
            message: 'not serializable',
            trace: '',
        )));

        self::assertInstanceOf(ExceptionMessage::class, $msg);
        self::assertSame(\LogicException::class, $msg->class);
        self::assertNull($msg->serialized);
    }

    public function test_round_trip_init_message(): void
    {
        $closure = base64_encode('fake-closure-bytes');
        $msg = Codec::decode(Codec::encode(new InitMessage($closure)));

        self::assertInstanceOf(InitMessage::class, $msg);
        self::assertSame($closure, $msg->closure);
    }

    public function test_round_trip_release_with_inject(): void
    {
        $inject = ['extra' => 'data'];
        $msg = Codec::decode(Codec::encode(new ReleaseMessage('loaded', $inject)));

        self::assertInstanceOf(ReleaseMessage::class, $msg);
        self::assertSame('loaded', $msg->name);
        self::assertSame($inject, $msg->inject);
    }

    public function test_round_trip_release_with_null_inject(): void
    {
        $msg = Codec::decode(Codec::encode(new ReleaseMessage('done')));

        self::assertInstanceOf(ReleaseMessage::class, $msg);
        self::assertSame('done', $msg->name);
        self::assertNull($msg->inject);
    }

    public function test_round_trip_abort_message(): void
    {
        $msg = Codec::decode(Codec::encode(new AbortMessage()));

        self::assertInstanceOf(AbortMessage::class, $msg);
        self::assertSame('abort', $msg->type());
    }

    public function test_encoded_line_contains_no_embedded_newline(): void
    {
        $line = Codec::encode(new CheckpointMessage('step', ['a' => "line1\nline2"]));

        // The line must not have a newline except at the very end.
        self::assertSame(1, substr_count($line, "\n"));
        self::assertStringEndsWith("\n", $line);
    }

    public function test_decode_throws_on_invalid_json(): void
    {
        $this->expectException(ProtocolException::class);
        $this->expectExceptionMessageMatches('/JSON decode failed/');

        Codec::decode('not json at all');
    }

    public function test_decode_throws_on_missing_type(): void
    {
        $this->expectException(ProtocolException::class);
        $this->expectExceptionMessageMatches('/Missing or invalid "type"/');

        Codec::decode('{"name":"foo"}');
    }

    public function test_decode_throws_on_unknown_type(): void
    {
        $this->expectException(ProtocolException::class);
        $this->expectExceptionMessageMatches('/Unknown message type "bogus"/');

        Codec::decode('{"type":"bogus"}');
    }

    public function test_decode_trims_surrounding_whitespace(): void
    {
        $msg = Codec::decode("  {\"type\":\"ready\"}  \n");

        self::assertInstanceOf(ReadyMessage::class, $msg);
    }
}
