<?php

declare(strict_types=1);

namespace IamExample\Infrastructure;

use Doctrine\DBAL\Connection;
use IamExample\Infrastructure\Rows\IamSchema;
use Ultra\Schema\PostgresSqlGenerator;
use Ultra\Schema\SchemaDefinition;
use Ultra\Schema\SchemaReconciler;

final class Schema
{
    public static function install(Connection $connection): void
    {
        $connection->transactional(static function (Connection $connection): void {
            // The IAM tables are installed together, so the tenant table marks a completed installation.
            if ($connection->fetchOne("SELECT to_regclass(quote_ident(current_schema()) || '.' || quote_ident(?)) IS NOT NULL", [
                IamSchema::TENANTS,
            ])) {
                return;
            }

            $operations = new SchemaReconciler()->reconcile(new SchemaDefinition([]), IamSchema::definition());
            foreach (new PostgresSqlGenerator()->generate($operations) as $statement) {
                $connection->executeStatement($statement);
            }
        });
    }
}
