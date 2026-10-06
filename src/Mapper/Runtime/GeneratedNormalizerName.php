<?php

declare(strict_types=1);

namespace Ultra\Mapper;

/** Names a generated class and its artifact consistently across compilation and runtime. */
final class GeneratedNormalizerName
{
    public const string DEFAULT_NAMESPACE = 'Ultra\Normalizer\Generated';

    public static function forType(string $className, string $generationNamespace): string
    {
        return trim($generationNamespace, '\\').'\\__Normalizer_'.str_replace('\\', '_', ltrim($className, '\\'));
    }

    public static function cacheFileName(string $normalizerClass): string
    {
        return hash('sha256', $normalizerClass).'.php';
    }
}
