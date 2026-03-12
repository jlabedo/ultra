<?php

declare(strict_types=1);

namespace Ultra\Mapper_Bundle\Cache;

use Symfony\Component\HttpKernel\CacheWarmer\CacheWarmerInterface;
use Ultra\Mapper_Compiler\NormalizerGenerator;

final class MapperCacheWarmer implements CacheWarmerInterface
{
    /**
     * @param list<class-string> $classes
     * @param array<class-string, true> $runtimeNormalizedTypes
     */
    public function __construct(
        private readonly array $classes,
        private readonly string $cacheDir,
        private readonly array $runtimeNormalizedTypes,
    ) {
    }

    public function warmUp(string $cacheDir): array
    {
        if (!class_exists(NormalizerGenerator::class)) {
            throw new \RuntimeException('Cannot warm mapper cache because ultra/mapper-compiler is not installed.');
        }

        if (!is_dir($this->cacheDir)) {
            mkdir($this->cacheDir, 0o777, true);
        }

        foreach ($this->classes as $className) {
            if (!class_exists($className)) {
                throw new \RuntimeException(sprintf('Cannot warm mapper cache for missing class "%s".', $className));
            }
            $normalizerClassBaseName = '__Normalizer_' . str_replace('\\', '_', ltrim($className, '\\'));
            $normalizerClass = 'Ultra\\Normalizer\\Generated\\' . $normalizerClassBaseName;
            $cacheFile = $this->cacheDir . '/' . $normalizerClassBaseName . '.php';

            if (!file_exists($cacheFile)) {
                $fileGen = NormalizerGenerator::for($className, $normalizerClass, $this->runtimeNormalizedTypes);
                file_put_contents($cacheFile, $fileGen->generate());
            }
        }

        return [];
    }

    public function isOptional(): bool
    {
        return false;
    }
}
