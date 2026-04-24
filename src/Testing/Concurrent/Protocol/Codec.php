<?php

declare(strict_types=1);

namespace Ultra\Testing\Concurrent\Protocol;

use Ultra\Testing\Concurrent\Exception\ProtocolException;

final class Codec
{
    public static function encode(Message $message): string
    {
        $data = match (true) {
            $message instanceof ReadyMessage => [
                'type' => 'ready',
            ],
            $message instanceof CheckpointMessage => [
                'type' => 'checkpoint',
                'name' => $message->name,
                'payload' => $message->payload,
            ],
            $message instanceof LogMessage => [
                'type' => 'log',
                'level' => $message->level,
                'message' => $message->message,
            ],
            $message instanceof ResultMessage => [
                'type' => 'result',
                'value' => $message->value,
            ],
            $message instanceof ExceptionMessage => array_filter([
                'type' => 'exception',
                'class' => $message->class,
                'message' => $message->message,
                'trace' => $message->trace,
                'serialized' => $message->serialized,
            ], static fn (mixed $v): bool => null !== $v),
            $message instanceof InitMessage => [
                'type' => 'init',
                'closure' => $message->closure,
            ],
            $message instanceof ReleaseMessage => [
                'type' => 'release',
                'name' => $message->name,
                'inject' => $message->inject,
            ],
            $message instanceof AbortMessage => [
                'type' => 'abort',
            ],
            default => throw new ProtocolException(sprintf('Cannot encode unknown message class: %s', $message::class)),
        };

        return json_encode($data, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE)."\n";
    }

    public static function decode(string $line): Message
    {
        $line = trim($line);

        try {
            /** @var mixed $data */
            $data = json_decode($line, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            throw new ProtocolException(
                sprintf('JSON decode failed: %s (line: %s)', $e->getMessage(), $line),
                previous: $e,
            );
        }

        if (!is_array($data) || !isset($data['type']) || !is_string($data['type'])) {
            throw new ProtocolException(sprintf('Missing or invalid "type" field (line: %s)', $line));
        }

        return match ($data['type']) {
            'ready' => new ReadyMessage(),
            'checkpoint' => new CheckpointMessage(
                name: (string) ($data['name'] ?? ''),
                payload: $data['payload'] ?? null,
            ),
            'log' => new LogMessage(
                level: (string) ($data['level'] ?? 'info'),
                message: (string) ($data['message'] ?? ''),
            ),
            'result' => new ResultMessage(
                value: (string) ($data['value'] ?? ''),
            ),
            'exception' => new ExceptionMessage(
                class: (string) ($data['class'] ?? ''),
                message: (string) ($data['message'] ?? ''),
                trace: (string) ($data['trace'] ?? ''),
                serialized: isset($data['serialized']) ? (string) $data['serialized'] : null,
            ),
            'init' => new InitMessage(
                closure: (string) ($data['closure'] ?? ''),
            ),
            'release' => new ReleaseMessage(
                name: (string) ($data['name'] ?? ''),
                inject: $data['inject'] ?? null,
            ),
            'abort' => new AbortMessage(),
            default => throw new ProtocolException(
                sprintf('Unknown message type "%s" (line: %s)', $data['type'], $line),
            ),
        };
    }
}
