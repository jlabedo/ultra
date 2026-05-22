<?php

declare(strict_types=1);

namespace Ultra\CodeGeneration;

final class GeneratedPhpClassWriter
{
    public function __construct(
        private readonly PhpFileWriter $fileWriter = new PhpFileWriter(),
    ) {}

    /**
     * @param callable(string): string $buildCode Receives the temporary class-name placeholder.
     *
     * @return array{className: string, filePath: string}
     */
    public function writeContentAddressed(string $baseName, string $outputDir, callable $buildCode, string $placeholder): array
    {
        $code = $buildCode($placeholder);
        $className = $baseName.'_'.substr(sha1($code), 0, 8);
        $code = str_replace($placeholder, $className, $code);
        $filePath = rtrim($outputDir, '/').'/'.$className.'.php';

        $this->fileWriter->write($filePath, $code);

        return ['className' => $className, 'filePath' => $filePath];
    }
}
