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

    public function head(): ?SchemaSnapshot
    {
        return $this->read()['head'];
    }

    /** @return list<array{id: string, snapshotHash: ?string, sql: ?string, sqlHash: ?string}> */
    public function migrations(): array
    {
        return $this->read()['migrations'];
    }

    /** @return array{head: ?SchemaSnapshot, migrations: list<array{id: string, snapshotHash: ?string, sql: ?string, sqlHash: ?string}>} */
    private function read(): array
    {
        clearstatcache();
        if (!file_exists($this->directory) && !is_link($this->directory)) {
            return ['head' => null, 'migrations' => []];
        }

        return $this->locked(LOCK_SH, $this->readLocked(...));
    }

    /** @return array{head: ?SchemaSnapshot, migrations: list<array{id: string, snapshotHash: ?string, sql: ?string, sqlHash: ?string}>} */
    private function readLocked(): array
    {
        $entries = self::filesystem(fn(): array|false => scandir($this->directory));
        if (false === $entries) {
            throw new \RuntimeException(sprintf('Cannot read migration history "%s".', $this->directory));
        }

        $migrations = [];
        $previous = null;
        $number = 1;
        foreach ($entries as $id) {
            if (str_starts_with($id, '.') || 'snapshot.lock' === $id) {
                continue;
            }

            $path = $this->directory . '/' . $id;
            $baseline = self::BASELINE === $id;
            $expectedNumber = sprintf('%06d', $number);
            if (
                !is_dir($path)
                || $baseline && null !== $previous
                || !$baseline && !preg_match('/\A' . $expectedNumber . '-[a-z0-9]+(?:-[a-z0-9]+)*\z/', $id)
            ) {
                throw new \UnexpectedValueException(sprintf(
                    'Invalid or divergent migration history at "%s". Expected migration number %s.',
                    $path,
                    $expectedNumber,
                ));
            }

            $sqlPath = $path . '/migration.sql';
            $snapshotPath = $path . '/snapshot.lock';
            $metadataPath = $path . '/migration.lock';
            if ($baseline) {
                if (!is_file($snapshotPath) || file_exists($sqlPath) || file_exists($metadataPath)) {
                    throw new \UnexpectedValueException(sprintf('Incomplete or invalid migration entry "%s".', $path));
                }
                $json = self::readFile($snapshotPath);
                $previous = self::snapshot($json, $snapshotPath)->revision;
                self::validateRevision($previous, $id);
                $migrations[] = [
                    'id' => $id,
                    'snapshotHash' => hash('sha256', $json),
                    'sql' => null,
                    'sqlHash' => null,
                ];

                continue;
            }

            if (!is_file($sqlPath) || !is_file($metadataPath) || file_exists($snapshotPath)) {
                throw new \UnexpectedValueException(sprintf('Incomplete or invalid migration entry "%s".', $path));
            }
            $metadata = json_decode(self::readFile($metadataPath), flags: JSON_THROW_ON_ERROR);
            if (!$metadata instanceof \stdClass || !property_exists($metadata, 'previous')) {
                throw new \UnexpectedValueException(sprintf('Invalid migration metadata in "%s".', $metadataPath));
            }
            $predecessor = $metadata->previous;
            if ($predecessor !== $previous) {
                throw new \UnexpectedValueException(sprintf(
                    'Divergent migration history at "%s". Expected predecessor "%s", found "%s".',
                    $path,
                    $previous ?? '(none)',
                    is_string($predecessor) ? $predecessor : '(none)',
                ));
            }
            $revision = self::validateRevision($metadata->revision ?? null, $id);
            $sql = self::readFile($sqlPath);
            if ('' === trim($sql)) {
                throw new \UnexpectedValueException(sprintf('Empty migration script "%s".', $sqlPath));
            }
            $previous = $revision;
            ++$number;
            $migrations[] = ['id' => $id, 'snapshotHash' => null, 'sql' => $sql, 'sqlHash' => hash('sha256', $sql)];
        }

        $snapshotPath = $this->directory . '/snapshot.lock';
        $head = file_exists($snapshotPath) || is_link($snapshotPath)
            ? self::snapshot(self::readFile($snapshotPath), $snapshotPath)
            : null;
        if ($previous !== $head?->revision) {
            throw new \UnexpectedValueException(sprintf(
                'The root snapshot does not match the migration history. Expected revision "%s", found "%s".',
                $previous ?? '(none)',
                $head->revision ?? '(none)',
            ));
        }

        return ['head' => $head, 'migrations' => $migrations];
    }

    private static function validateRevision(mixed $revision, string $id): string
    {
        if (!is_string($revision) || !preg_match('/\A' . $id . ':[a-f0-9]{64}\z/', $revision)) {
            throw new \UnexpectedValueException(sprintf('Invalid revision for migration "%s".', $id));
        }

        return $revision;
    }

    private static function snapshot(string $json, string $path): SchemaSnapshot
    {
        try {
            return SchemaSnapshot::fromJson($json);
        } catch (\JsonException|\LogicException|\RuntimeException $exception) {
            throw new \UnexpectedValueException(
                sprintf('Invalid snapshot in "%s": %s', $path, $exception->getMessage()),
                previous: $exception,
            );
        }
    }

    public function append(?string $previous, SchemaDefinition $schema, ?string $name, ?string $sql): string
    {
        $this->createDirectory();

        return $this->locked(LOCK_EX, function () use ($previous, $schema, $name, $sql): string {
            $current = $this->readLocked()['head'];
            if ($previous !== $current?->revision) {
                throw new \RuntimeException('Migration history changed during generation. Run the command again.');
            }
            $number = (int) substr($previous ?? '', 0, 6) + 1;
            if ($number > 999_999) {
                throw new \RuntimeException('Migration history has exhausted its sequence numbers.');
            }
            $id = null === $name ? self::BASELINE : sprintf('%06d-%s', $number, $name);
            $snapshot = new SchemaSnapshot($id, $schema);
            $this->publish($id, $snapshot, $previous, $sql);

            return $id;
        });
    }

    /**
     * @template T
     * @param \Closure(): T $operation
     * @return T
     */
    private function locked(int $mode, \Closure $operation): mixed
    {
        $lock = self::filesystem(fn() => fopen($this->directory, 'r'));
        if (false === $lock) {
            throw new \RuntimeException(sprintf('Cannot open migration history "%s".', $this->directory));
        }
        try {
            if (!flock($lock, $mode)) {
                throw new \RuntimeException(sprintf('Cannot lock migration history "%s".', $this->directory));
            }

            return $operation();
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

    private function publish(string $id, SchemaSnapshot $snapshot, ?string $previous, ?string $sql): void
    {
        $json = $snapshot->toJson();
        $destination = $this->directory . '/' . $id;
        $staging = $this->directory . '/.pending-' . bin2hex(random_bytes(8));
        if (!self::filesystem(static fn(): bool => mkdir($staging))) {
            throw new \RuntimeException(sprintf('Cannot create staging directory "%s".', $staging));
        }

        $entry = $staging . '/entry';
        $files = null === $sql
            ? ['snapshot.lock' => $json]
            : [
                'migration.sql' => $sql,
                'migration.lock' =>
                    json_encode([
                        'previous' => $previous,
                        'revision' => $snapshot->revision,
                    ], JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT) . "\n",
            ];

        try {
            self::filesystem(static fn(): bool => mkdir($entry));
            foreach ($files as $file => $contents) {
                self::write($entry . '/' . $file, $contents);
            }
            self::write($staging . '/snapshot.lock', $json);
            if (file_exists($destination) || is_link($destination)) {
                throw new \RuntimeException(sprintf('Migration entry "%s" already exists.', $destination));
            }
            self::move($entry, $destination);
            try {
                self::move($staging . '/snapshot.lock', $this->directory . '/snapshot.lock');
            } catch (\Throwable $exception) {
                self::move($destination, $entry);

                throw $exception;
            }
        } finally {
            if (is_dir($entry)) {
                foreach (array_keys($files) as $file) {
                    self::removeFileIfPresent($entry . '/' . $file);
                }
                self::filesystem(static fn(): bool => rmdir($entry));
            }
            self::removeFileIfPresent($staging . '/snapshot.lock');
            self::filesystem(static fn(): bool => rmdir($staging));
        }
    }

    private static function removeFileIfPresent(string $path): void
    {
        if (is_file($path)) {
            self::filesystem(static fn(): bool => unlink($path));
        }
    }

    private static function move(string $source, string $destination): void
    {
        if (!self::filesystem(static fn(): bool => rename($source, $destination))) {
            throw new \RuntimeException(sprintf('Cannot publish "%s".', $destination));
        }
    }

    private static function readFile(string $path): string
    {
        $contents = self::filesystem(static fn(): string|false => file_get_contents($path));
        if (false === $contents) {
            throw new \RuntimeException(sprintf('Cannot read "%s".', $path));
        }

        return $contents;
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
