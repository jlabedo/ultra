<?php

declare(strict_types=1);

namespace Ultra\CodeGeneration\Tests;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Ultra\CodeGeneration\GeneratedPhpClassWriter;
use Ultra\CodeGeneration\PhpFileWriter;

#[CoversClass(PhpFileWriter::class)]
#[CoversClass(GeneratedPhpClassWriter::class)]
final class GeneratedArtifactsTest extends TestCase
{
    private string $directory;

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir().'/ultra-code-generation-'.bin2hex(random_bytes(8));
    }

    public function test_php_files_can_be_loaded_and_replaced_in_nested_directories(): void
    {
        $path = $this->directory.'/nested/value.php';
        $writer = new PhpFileWriter();

        foreach ([1, 2] as $value) {
            $writer->write($path, '<?php return '.$value.';');
            self::assertSame($value, require $path);
        }
    }

    public function test_class_artifacts_are_executable_and_addressed_by_content(): void
    {
        $writer = new GeneratedPhpClassWriter();
        $baseName = 'CompiledThing_'.bin2hex(random_bytes(8));
        $code = static fn (string $className): string => "<?php\nfinal class {$className} { public function value(): int { return 1; } }\n";
        $result = $writer->writeContentAddressed($baseName, $this->directory, $code, '__CLASS__');
        $repeat = $writer->writeContentAddressed($baseName, $this->directory.'/', $code, '__CLASS__');
        $changed = $writer->writeContentAddressed($baseName, $this->directory, static fn (string $className): string => str_replace('return 1;', 'return 2;', $code($className)), '__CLASS__');

        self::assertSame($result, $repeat);
        self::assertStringStartsWith($baseName.'_', $result['className']);
        self::assertSame($this->directory.'/'.$result['className'].'.php', $result['filePath']);
        self::assertSame($code($result['className']), file_get_contents($result['filePath']));
        self::assertNotSame($result['className'], $changed['className']);
        foreach ([1 => $result, 2 => $changed] as $value => $artifact) {
            require $artifact['filePath'];
            $className = $artifact['className'];
            self::assertSame($value, new $className()->value());
        }
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->directory);
        parent::tearDown();
    }

    private function removeDirectory(string $directory): void
    {
        if (!is_dir($directory)) {
            return;
        }
        chmod($directory, 0o700);
        foreach (new \FilesystemIterator($directory) as $file) {
            if ($file->isDir()) {
                $this->removeDirectory($file->getPathname());
            } else {
                chmod($file->getPathname(), 0o600);
                unlink($file->getPathname());
            }
        }
        rmdir($directory);
    }
}
