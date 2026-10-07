<?php

declare(strict_types=1);

namespace Ultra\Mapper;

/** Names a generated class and its artifact consistently across compilation and runtime. */
final class GeneratedNormalizerName
{
    public const string DEFAULT_NAMESPACE = 'Ultra\Normalizer\Generated';

    public static function forType(string $className, string $generationNamespace): string
    {
        $className = ltrim($className, '\\');

        return trim($generationNamespace, '\\') . '\\__Normalizer_' . strtr($className, ['_' => '__', '\\' => '_s']);
    }

    public static function cacheFileName(string $normalizerClass): string
    {
        return hash('sha256', $normalizerClass) . '.php';
    }
}
