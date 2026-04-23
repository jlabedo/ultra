<?php

declare(strict_types=1);

namespace Ultra\Testing\Concurrent\Internal;

use Ultra\Testing\Concurrent\Protocol\AbortMessage;
use Ultra\Testing\Concurrent\Protocol\CheckpointMessage;
use Ultra\Testing\Concurrent\Protocol\Codec;
use Ultra\Testing\Concurrent\Protocol\LogMessage;
use Ultra\Testing\Concurrent\Protocol\Message;
use Ultra\Testing\Concurrent\Protocol\ReleaseMessage;

/**
 * Static helpers used inside the child process. Intentionally uses STDIN/STDOUT
 * constants directly — this code only runs in the worker subprocess.
 */
final class WorkerRuntime
{
    public static function emit(Message $message): void
    {
        fwrite(STDOUT, Codec::encode($message));
        fflush(STDOUT);
    }

    /**
     * Announce a checkpoint, then block until the master sends a matching release.
     *
     * Returns the inject value from the release message (null if not provided).
     */
    public static function checkpoint(string $name, mixed $payload = null): mixed
    {
        self::emit(new CheckpointMessage($name, $payload));

        while (true) {
            $line = fgets(STDIN);

            if ($line === false) {
                fwrite(STDERR, sprintf("Unexpected EOF on stdin while waiting for release of checkpoint '%s'\n", $name));
                exit(3);
            }

            $line = trim($line);
            if ($line === '') {
                continue;
            }

            try {
                $message = Codec::decode($line);
            } catch (\Throwable $e) {
                fwrite(STDERR, sprintf("Protocol error: %s\n", $e->getMessage()));
                exit(3);
            }

            if ($message instanceof ReleaseMessage && $message->name === $name) {
                return $message->inject;
            }

            if ($message instanceof AbortMessage) {
                exit(2);
            }

            fwrite(STDERR, sprintf(
                "Unexpected message type '%s' while waiting for release of checkpoint '%s'\n",
                $message->type(),
                $name,
            ));
            exit(3);
        }
    }

    public static function log(string $message, string $level = 'info'): void
    {
        self::emit(new LogMessage($level, $message));
    }
}
