#!/usr/bin/env php
<?php

declare(strict_types=1);

$toolsDir = dirname(__DIR__);
$composerJson = $toolsDir . '/composer.json';

if (!is_file($composerJson)) {
    fwrite(STDERR, sprintf("Missing tools Composer manifest: %s\n", $composerJson));

    exit(1);
}

$composer = getenv('COMPOSER_BINARY') ?: 'composer';
$command = [
    $composer,
    'install',
    '--working-dir=' . $toolsDir,
    '--no-interaction',
    '--no-progress',
];

if (getenv('CI') !== false) {
    $command[] = '--prefer-dist';
}

$exitCode = run($command);

exit($exitCode);

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
