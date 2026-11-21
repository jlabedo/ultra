<?php

declare(strict_types=1);

namespace MappedTableSymfonyExample;

use Doctrine\DBAL\Connection;
use Symfony\Component\DependencyInjection\Container;
use Symfony\Component\DependencyInjection\Dumper\PhpDumper;

final readonly class WorkshopContainer
{
    /** @param class-string<Container> $class */
    private function __construct(
        private string $class,
    ) {}

    public static function compile(): self
    {
        $builder = require dirname(__DIR__) . '/config/services.php';
        $builder->compile();

        $class = 'MappedTableWorkshopContainer' . bin2hex(random_bytes(8));
        $file = tempnam(sys_get_temp_dir(), 'mapped-table-workshops-');
        if (false === $file) {
            throw new \RuntimeException('Cannot create a file for the compiled workshop container.');
        }

        try {
            $php = new PhpDumper($builder)->dump(['class' => $class]);
            if (false === file_put_contents($file, $php)) {
                throw new \RuntimeException('Cannot write the compiled workshop container.');
            }
            require $file;
        } finally {
            unlink($file);
        }

        return new self($class);
    }

    public function using(Connection $connection): Container
    {
        $container = new $this->class();
        $container->set(Connection::class, $connection);

        return $container;
    }
}
