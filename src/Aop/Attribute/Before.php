<?php

declare(strict_types=1);

namespace Ultra\Aop\Attribute;

#[\Attribute(\Attribute::TARGET_METHOD)]
class Before extends Advice {}
