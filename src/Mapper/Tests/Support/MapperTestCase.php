<?php

declare(strict_types=1);

namespace Ultra\Mapper\Tests\Support;

use PHPUnit\Framework\TestCase;
use Ultra\Mapper\UltraNormalizer;

require_once __DIR__.'/MappingFixtures.php';

/** @internal */
abstract class MapperTestCase extends TestCase
{
    protected string $directory;

    /** @var list<string> */
    protected array $extraDirectories = [];

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir().'/ultra_mapper_'.bin2hex(random_bytes(8));
    }

    protected function normalizer(): UltraNormalizer
    {
        return new UltraNormalizer($this->directory.'/normalizers');
    }

    /** @return array<string, string> Files addressed by their real paths, with their contents. */
    protected function generatedFiles(string $directory): array
    {
        if (!is_dir($directory)) {
            return [];
        }

        $files = [];
        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($directory, \FilesystemIterator::SKIP_DOTS)) as $file) {
            $files[$file->getRealPath()] = (string) file_get_contents($file->getPathname());
        }
        ksort($files);

        return $files;
    }

    protected function tearDown(): void
    {
        foreach ([$this->directory, ...$this->extraDirectories] as $directory) {
            $this->removeDirectory($directory);
        }

        parent::tearDown();
    }

    protected function removeDirectory(string $directory): void
    {
        if (!is_dir($directory)) {
            return;
        }

        // A mkdir permission mutant must not poison the next test's setup.
        chmod($directory, 0o700);
        foreach (new \FilesystemIterator($directory, \FilesystemIterator::SKIP_DOTS) as $file) {
            if ($file->isDir()) {
                $this->removeDirectory($file->getPathname());
            } else {
                unlink($file->getPathname());
            }
        }
        rmdir($directory);
    }
}
