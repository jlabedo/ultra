<?php

declare(strict_types=1);

if (!isset($toolName) || !is_string($toolName) || $toolName === '') {
    fwrite(STDERR, "Tool proxy was called without a tool name.\n");

    exit(1);
}

$toolsDir = dirname(__DIR__);
$toolBinary = $toolsDir . '/vendor/bin/' . $toolName;

if ($toolName === 'phpunit' && !is_file(dirname($toolsDir) . '/vendor/autoload.php')) {
    fwrite(STDERR, "Run composer install before running PHPUnit.\n");

    exit(1);
}

if (!is_file($toolBinary)) {
    fwrite(STDERR, sprintf("Installing development tools before running %s...\n", $toolName));

    $installer = $toolsDir . '/bootstrap/install.php';
    $exitCode = run([PHP_BINARY, $installer]);

    if ($exitCode !== 0) {
        exit($exitCode);
    }
}

if (!is_file($toolBinary)) {
    fwrite(STDERR, sprintf("Tool binary was not installed: %s\n", $toolBinary));

    exit(1);
}

if ($toolName === 'phpunit') {
    // Keep Infection's interpreter options and coverage extensions in the PHPUnit process.
    require $toolBinary;

    exit(0);
}

exit(run(array_merge([PHP_BINARY, $toolBinary], array_slice($_SERVER['argv'], 1))));

/**
 * @param list<string> $command
 */
function run(array $command): int
{
    $process = proc_open(
        array_map('strval', $command),
        [
            0 => STDIN,
            1 => STDOUT,
            2 => STDERR,
        ],
        $pipes,
    );

    if (!is_resource($process)) {
        fwrite(STDERR, sprintf("Unable to start command: %s\n", implode(' ', $command)));

        return 1;
    }

    return proc_close($process);
}
