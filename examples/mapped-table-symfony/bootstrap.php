<?php

declare(strict_types=1);

if (is_file(__DIR__ . '/vendor/autoload.php')) {
    require_once __DIR__ . '/vendor/autoload.php';
} else {
    $loader = require __DIR__ . '/../../vendor/autoload.php';
    $loader->addPsr4('MappedTableSymfonyExample\\', __DIR__ . '/src');
}
