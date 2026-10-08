<?php

declare(strict_types=1);

namespace Ultra\Testing;

use Doctrine\DBAL\Driver;
use Doctrine\DBAL\Driver\Connection;
use Doctrine\DBAL\Driver\Middleware;
use Doctrine\DBAL\Driver\Middleware\AbstractConnectionMiddleware;
use Doctrine\DBAL\Driver\Middleware\AbstractDriverMiddleware;
use Doctrine\DBAL\Driver\Middleware\AbstractStatementMiddleware;
use Doctrine\DBAL\Driver\Result;
use Doctrine\DBAL\Driver\Statement;
use Doctrine\DBAL\ParameterType;

/** Install in Configuration before creating the connection. Does not consume or modify query results. */
final readonly class SqlTraceMiddleware implements Middleware
{
    public function __construct(
        private SqlTrace $trace,
    ) {}

    public function wrap(Driver $driver): Driver
    {
        return new class($driver, $this->trace) extends AbstractDriverMiddleware {
            public function __construct(
                Driver $driver,
                private readonly SqlTrace $trace,
            ) {
                parent::__construct($driver);
            }

            public function connect(#[\SensitiveParameter] array $params): Connection
            {
                return new class(parent::connect($params), $this->trace) extends AbstractConnectionMiddleware {
                    public function __construct(
                        Connection $connection,
                        private readonly SqlTrace $trace,
                    ) {
                        parent::__construct($connection);
                    }

                    public function prepare(string $sql): Statement
                    {
                        $statement = $this->trace->record('prepare', $sql, [], [], fn(): Statement => parent::prepare(
                            $sql,
                        ));
                        return new class($statement, $this->trace, $sql) extends AbstractStatementMiddleware {
                            /** @var array<array-key, mixed> */
                            private array $parameters = [];
                            /** @var array<array-key, ParameterType> */
                            private array $types = [];

                            public function __construct(
                                Statement $statement,
                                private readonly SqlTrace $trace,
                                private readonly string $sql,
                            ) {
                                parent::__construct($statement);
                            }

                            public function bindValue(int|string $param, mixed $value, ParameterType $type): void
                            {
                                parent::bindValue($param, $value, $type);
                                $this->parameters[$param] = $value;
                                $this->types[$param] = $type;
                            }

                            public function execute(): Result
                            {
                                return $this->trace->record(
                                    'execute',
                                    $this->sql,
                                    $this->parameters,
                                    $this->types,
                                    parent::execute(...),
                                );
                            }
                        };
                    }

                    public function query(string $sql): Result
                    {
                        return $this->trace->record('query', $sql, [], [], fn(): Result => parent::query($sql));
                    }

                    public function exec(string $sql): int|string
                    {
                        return $this->trace->record('exec', $sql, [], [], fn(): int|string => parent::exec($sql));
                    }

                    public function beginTransaction(): void
                    {
                        $this->trace->record('begin', null, [], [], function (): void {
                            parent::beginTransaction();
                        });
                    }

                    public function commit(): void
                    {
                        $this->trace->record('commit', null, [], [], function (): void {
                            parent::commit();
                        });
                    }

                    public function rollBack(): void
                    {
                        $this->trace->record('rollback', null, [], [], function (): void {
                            parent::rollBack();
                        });
                    }
                };
            }
        };
    }
}
