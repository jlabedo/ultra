<?php

declare(strict_types=1);

namespace Ultra\CodeGeneration;

final class PhpFileWriter
{
    public function write(string $filePath, string $code): void
    {
        $dir = dirname($filePath);
        if (!is_dir($dir)) {
            mkdir($dir, 0o777, true);
        }

        file_put_contents($filePath, $code);
    }
}
