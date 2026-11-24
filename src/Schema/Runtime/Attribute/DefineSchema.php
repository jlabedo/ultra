<?php

declare(strict_types=1);

namespace Ultra\Schema\Attribute;

/** Marks a public method that provides a schema fragment. */
#[\Attribute(\Attribute::TARGET_METHOD)]
final class DefineSchema {}
