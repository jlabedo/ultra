<?php

declare(strict_types=1);

require_once is_file(__DIR__ . '/vendor/autoload.php')
    ? __DIR__ . '/vendor/autoload.php'
    : __DIR__ . '/../../vendor/autoload.php';

spl_autoload_register(static function (string $class): void {
    $prefix = 'MappedTableSqlExample\\';
    if (str_starts_with($class, $prefix)) {
        $file = __DIR__ . '/src/' . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
        if (is_file($file)) {
            require_once $file;
        }
    }
});
