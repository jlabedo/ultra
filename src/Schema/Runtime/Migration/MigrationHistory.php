<?php

declare(strict_types=1);

namespace Ultra\Schema\Migration;

use Ultra\Schema\SchemaDefinition;

use function strlen;

/** @internal */
final readonly class MigrationHistory
{
    private const string BASELINE = '000000-baseline';

    public function __construct(
        private string $directory,
    ) {}

    /** @return ?array{id: string, schema: SchemaDefinition} */
    public function head(): ?array
    {
        clearstatcache();
        if (!file_exists($this->directory) && !is_link($this->directory)) {
            return null;
        }

        $entries = self::filesystem(fn(): array|false => scandir($this->directory));
        if (false === $entries) {
            throw new \RuntimeException(sprintf('Cannot read migration history "%s".', $this->directory));
        }

        $head = null;
        foreach ($entries as $id) {
            if (str_starts_with($id, '.')) {
                continue;
            }

            $path = $this->directory . '/' . $id;
            $baseline = self::BASELINE === $id;
            $expectedNumber = sprintf('%06d', self::nextNumber($head['id'] ?? null));
            if (
                !is_dir($path)
                || $baseline && null !== $head
                || !$baseline && !preg_match('/\A' . $expectedNumber . '-[a-z0-9]+(?:-[a-z0-9]+)*\z/', $id)
            ) {
                throw new \UnexpectedValueException(sprintf(
                    'Invalid or divergent migration history at "%s". Expected migration number %s.',
                    $path,
                    $expectedNumber,
                ));
            }

            $snapshotPath = $path . '/snapshot.json';
            if (!is_file($snapshotPath) || $baseline === is_file($path . '/migration.sql')) {
                throw new \UnexpectedValueException(sprintf('Incomplete or invalid migration entry "%s".', $path));
            }

            try {
                $json = self::filesystem(static fn(): string|false => file_get_contents($snapshotPath));
                if (false === $json) {
                    throw new \UnexpectedValueException('Cannot read snapshot file.');
                }
                $snapshot = SchemaSnapshot::fromJson($json);
            } catch (\JsonException|\LogicException|\RuntimeException $exception) {
                throw new \UnexpectedValueException(
                    sprintf('Invalid snapshot in "%s": %s', $path, $exception->getMessage()),
                    previous: $exception,
                );
            }

            if ($snapshot->previous !== ($head['id'] ?? null)) {
                throw new \UnexpectedValueException(sprintf(
                    'Divergent migration history at "%s". Expected predecessor "%s", found "%s".',
                    $path,
                    $head['id'] ?? '(none)',
                    $snapshot->previous ?? '(none)',
                ));
            }
            $head = ['id' => $id, 'schema' => $snapshot->schema];
        }

        return $head;
    }

    /** Publish the entire entry together, after checking that the history still has the expected head. */
    public function append(SchemaSnapshot $snapshot, ?string $name, ?string $sql): string
    {
        $json = $snapshot->toJson();
        $this->createDirectory();

        $lock = self::filesystem(fn() => fopen($this->directory, 'r'));
        if (false === $lock) {
            throw new \RuntimeException(sprintf('Cannot open migration history "%s".', $this->directory));
        }

        try {
            if (!flock($lock, LOCK_EX)) {
                throw new \RuntimeException(sprintf('Cannot lock migration history "%s".', $this->directory));
            }

            $current = $this->head();
            if ($snapshot->previous !== ($current['id'] ?? null)) {
                throw new \RuntimeException('Migration history changed during generation. Run the command again.');
            }

            $number = self::nextNumber($snapshot->previous);
            if ($number > 999_999) {
                throw new \RuntimeException('Migration history has exhausted its sequence numbers.');
            }

            $id = null === $name ? self::BASELINE : sprintf('%06d-%s', $number, $name);

            return $this->publish($id, $json, $sql);
        } finally {
            fclose($lock);
        }
    }

    private function createDirectory(): void
    {
        if (is_dir($this->directory)) {
            return;
        }

        try {
            self::filesystem(fn(): bool => mkdir($this->directory, recursive: true));
        } catch (\RuntimeException $exception) {
            // A concurrent first writer may have created the directory.
            if (!is_dir($this->directory)) {
                throw $exception;
            }
        }
    }

    private function publish(string $id, string $json, ?string $sql): string
    {
        $destination = $this->directory . '/' . $id;
        $staging = $this->directory . '/.pending-' . bin2hex(random_bytes(8));
        if (!self::filesystem(static fn(): bool => mkdir($staging))) {
            throw new \RuntimeException(sprintf('Cannot create staging directory "%s".', $staging));
        }

        try {
            self::write($staging . '/snapshot.json', $json);
            if (null !== $sql) {
                self::write($staging . '/migration.sql', $sql);
            }

            if (
                file_exists($destination)
                || is_link($destination)
                || !self::filesystem(static fn(): bool => rename($staging, $destination))
            ) {
                throw new \RuntimeException(sprintf('Cannot publish migration entry "%s".', $destination));
            }
        } finally {
            if (is_dir($staging)) {
                foreach (['snapshot.json', 'migration.sql'] as $file) {
                    $path = $staging . '/' . $file;
                    if (is_file($path)) {
                        self::filesystem(static fn(): bool => unlink($path));
                    }
                }
                self::filesystem(static fn(): bool => rmdir($staging));
            }
        }

        return $id;
    }

    private static function nextNumber(?string $previous): int
    {
        if (null === $previous) {
            return 1;
        }

        $number = (int) strstr($previous, '-', before_needle: true);

        return $number + 1;
    }

    private static function write(string $path, string $contents): void
    {
        $written = self::filesystem(static fn(): int|false => file_put_contents($path, $contents));
        if (strlen($contents) !== $written) {
            throw new \RuntimeException(sprintf('Cannot write migration file "%s".', $path));
        }
    }

    /**
     * @template Result
     * @param callable(): Result $operation
     * @return Result
     */
    private static function filesystem(callable $operation): mixed
    {
        set_error_handler(static function (int $severity, string $message): never {
            throw new \RuntimeException($message, $severity);
        }, E_WARNING);
        try {
            return $operation();
        } finally {
            restore_error_handler();
        }
    }
}
