<?php

declare(strict_types=1);

namespace Ultra\Mapper_Bundle\Cache;

use Symfony\Component\HttpKernel\CacheWarmer\CacheWarmerInterface;
use Ultra\Mapper_Compiler\NormalizerGenerator;

final class MapperCacheWarmer implements CacheWarmerInterface
{
    /**
     * @param list<class-string>        $classes
     * @param array<class-string, true> $runtimeNormalizedTypes
     */
    public function __construct(
        private readonly array $classes,
        private readonly array $runtimeNormalizedTypes,
    ) {}

    public function warmUp(string $cacheDir, ?string $buildDir = null): array
    {
        if (! class_exists(NormalizerGenerator::class)) {
            throw new \RuntimeException('Cannot warm mapper cache because ultra/mapper-compiler is not installed.');
        }

        $mapperBuildDir = rtrim((string) ($buildDir ?? $cacheDir), '/').'/mapper';

        if (! is_dir($mapperBuildDir)) {
            mkdir($mapperBuildDir, 0o777, true);
        }

        foreach ($this->classes as $className) {
            if (! class_exists($className)) {
                throw new \RuntimeException(sprintf('Cannot warm mapper cache for missing class "%s".', $className));
            }
            $normalizerClassBaseName = '__Normalizer_'.str_replace('\\', '_', ltrim($className, '\\'));
            $normalizerClass = 'Ultra\Normalizer\Generated\\'.$normalizerClassBaseName;
            $cacheFile = $mapperBuildDir.'/'.$normalizerClassBaseName.'.php';

            if (! file_exists($cacheFile)) {
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
