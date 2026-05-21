<?php

declare(strict_types=1);

// Disable output buffering — any stray echo/var_dump must not corrupt the JSON protocol stream.
while (ob_get_level()) {
    ob_end_clean();
}

$autoloadPath = getenv('ULTRA_AUTOLOAD');
if (false === $autoloadPath || '' === $autoloadPath) {
    fwrite(STDERR, "ULTRA_AUTOLOAD environment variable is not set or empty.\n");

    exit(3);
}

foreach (explode(PATH_SEPARATOR, $autoloadPath) as $path) {
    if (!file_exists($path)) {
        fwrite(STDERR, sprintf("Autoloader not found at path: %s\n", $path));

        exit(3);
    }

    require_once $path;
}

require_once __DIR__.'/functions.php';

use Ultra\Testing\Concurrent\Internal\WorkerRuntime;
use Ultra\Testing\Concurrent\Protocol\Codec;
use Ultra\Testing\Concurrent\Protocol\ExceptionMessage;
use Ultra\Testing\Concurrent\Protocol\InitMessage;
use Ultra\Testing\Concurrent\Protocol\ReadyMessage;
use Ultra\Testing\Concurrent\Protocol\ResultMessage;

// Read the init message — the master sends this immediately after starting the process.
$initLine = fgets(STDIN);
if (false === $initLine) {
    fwrite(STDERR, "Failed to read init message from stdin (EOF).\n");

    exit(3);
}

try {
    $initMessage = Codec::decode($initLine);
} catch (Throwable $e) {
    fwrite(STDERR, sprintf("Failed to decode init message: %s (line: %s)\n", $e->getMessage(), trim((string) $initLine)));

    exit(3);
}

if (!$initMessage instanceof InitMessage) {
    fwrite(STDERR, sprintf("Expected init message, got type '%s'.\n", $initMessage->type()));

    exit(3);
}

// Deserialize the closure.
try {
    $decoded = base64_decode($initMessage->closure, true);
    if (false === $decoded) {
        throw new RuntimeException('base64_decode failed on closure payload.');
    }

    /** @var mixed $closure */
    $closure = \Opis\Closure\unserialize($decoded);
} catch (Throwable $e) {
    fwrite(STDERR, sprintf(
        "Failed to unserialize closure: %s\n"
        ."Hint: captured variables must be serializable. Open connections, PDO instances, and resources cannot be captured — bootstrap them inside the closure.\n",
        $e->getMessage(),
    ));

    exit(3);
}

if (!$closure instanceof Closure) {
    fwrite(STDERR, "Deserialized payload is not a Closure.\n");

    exit(3);
}

// Signal readiness — closure is about to run.
WorkerRuntime::emit(new ReadyMessage());

// Run the closure and report the outcome.
try {
    $result = $closure();

    WorkerRuntime::emit(new ResultMessage(base64_encode(serialize($result))));

    exit(0);
} catch (Throwable $e) {
    $serialized = null;

    try {
        $serialized = base64_encode(serialize($e));
    } catch (Throwable) {
        // Not all exceptions are serializable; fall back to class/message.
    }

    WorkerRuntime::emit(new ExceptionMessage(
        class: $e::class,
        message: $e->getMessage(),
        trace: $e->getTraceAsString(),
        serialized: $serialized,
    ));

    exit(1);
}
