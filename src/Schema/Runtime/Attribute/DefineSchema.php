<?php

declare(strict_types=1);

namespace Ultra\Schema\Attribute;

/** Marks a public static method that provides a schema fragment at container compilation. */
#[\Attribute(\Attribute::TARGET_METHOD)]
final class DefineSchema {}
