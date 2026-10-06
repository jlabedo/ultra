<?php

declare(strict_types=1);

namespace Ultra\CodeGeneration;

final class GeneratedPhpClassWriter
{
    public function __construct(
        private readonly PhpFileWriter $fileWriter = new PhpFileWriter(),
    ) {}

    /**
     * Class identities depend on the generated content and output directory so
     * independent builds can be loaded in the same process.
     *
     * @param callable(string): string $buildCode Receives the temporary class-name placeholder.
     *
     * @return array{className: string, filePath: string}
     */
    public function writeContentAddressed(string $baseName, string $outputDir, callable $buildCode, string $placeholder): array
    {
        $code = $buildCode($placeholder);
        $outputDir = rtrim($outputDir, '/');
        $className = $baseName.'_'.substr(sha1($outputDir."\0".$code), 0, 8);
        $code = str_replace($placeholder, $className, $code);
        $filePath = $outputDir.'/'.$className.'.php';

        $this->fileWriter->write($filePath, $code);

        return ['className' => $className, 'filePath' => $filePath];
    }
}
