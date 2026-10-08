#!/usr/bin/env php
<?php

declare(strict_types=1);

use Doctrine\DBAL\Configuration;
use MappedTableSqlExample\Inspector;
use MappedTableSqlExample\Scenario;
use MappedTableSqlExample\TextReport;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\SingleCommandApplication;
use Ultra\Testing\PostgresConnectionSupport;
use Ultra\Testing\SqlTrace;
use Ultra\Testing\SqlTraceMiddleware;

require dirname(__DIR__) . '/bootstrap.php';

(new SingleCommandApplication())
    ->setName('MappedTable SQL inspection')
    ->addArgument('scenario', InputArgument::OPTIONAL, 'Built-in name or PHP scenario file', 'batch-writes')
    ->addOption('dsn', null, InputOption::VALUE_REQUIRED, 'PostgreSQL URL; defaults to ULTRA_TEST_POSTGRES_DSN')
    ->setCode(static function (InputInterface $input, OutputInterface $output): int {
        $selection = (string) $input->getArgument('scenario');
        $file = is_file($selection) ? $selection : dirname(__DIR__) . '/scenarios/' . $selection . '.php';
        if (!is_file($file)) {
            throw new InvalidArgumentException('Unknown scenario: ' . $selection);
        }
        $scenario = require $file;
        if (!$scenario instanceof Scenario) {
            throw new InvalidArgumentException('A scenario file must return a ' . Scenario::class . '.');
        }
        $dsn = $input->getOption('dsn') ?? (getenv('ULTRA_TEST_POSTGRES_DSN') ?: null);
        if (!is_string($dsn) || '' === $dsn) {
            throw new InvalidArgumentException('Set ULTRA_TEST_POSTGRES_DSN or pass --dsn with a PostgreSQL URL.');
        }
        $trace = new SqlTrace();
        $configuration = new Configuration();
        $configuration->setMiddlewares([new SqlTraceMiddleware($trace)]);
        $connection = PostgresConnectionSupport::connection($dsn, $configuration);
        try {
            $inspection = new Inspector($connection, $trace)->run($scenario);
            $output->write(TextReport::render($inspection), false, OutputInterface::OUTPUT_RAW);
            return $inspection->succeeded() ? 0 : 1;
        } finally {
            $connection->close();
        }
    })
    ->run();
