<?php

namespace Ultra\Mapper;

/**
 * Interface for custom (de)normalizers for specific types.
 */
interface RuntimeNormalizerInterface extends NormalizerInterface
{
    /**
     * @return iterable<class-string> list of fully qualified class names or type names this normalizer handles
     */
    public static function handledTypes(): iterable;
}
