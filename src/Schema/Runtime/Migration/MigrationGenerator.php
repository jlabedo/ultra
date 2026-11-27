<?php

declare(strict_types=1);

namespace Ultra\Schema\Migration;

use Ultra\Schema\Operations\DropColumn;
use Ultra\Schema\Operations\DropTable;
use Ultra\Schema\PostgresSqlGenerator;
use Ultra\Schema\SchemaDefinition;
use Ultra\Schema\SchemaReconciler;

final readonly class MigrationGenerator
{
    private MigrationHistory $history;

    public function __construct(string $directory)
    {
        $this->history = new MigrationHistory($directory);
    }

    /** Record the current definition of an existing database without generating SQL. */
    public function initialize(SchemaDefinition $schema): string
    {
        if (null !== $this->history->head()) {
            throw new \LogicException('Migration history already exists.');
        }
        $schema->validateForeignKeys();

        return $this->history->append(null, $schema, null, null);
    }

    /** Return the new migration ID, or null when the current and target definitions produce no operations. */
    public function generate(SchemaDefinition $target, string $name, bool $allowDestructive = false): ?string
    {
        if (!preg_match('/\A[a-z0-9]+(?:-[a-z0-9]+)*\z/', $name)) {
            throw new \InvalidArgumentException(
                'Use lowercase letters and digits separated by hyphens for migration names.',
            );
        }

        $head = $this->history->head();
        $operations = new SchemaReconciler()->reconcile($head->schema ?? new SchemaDefinition([]), $target);
        if ([] === $operations) {
            return null;
        }

        if (!$allowDestructive) {
            $deletions = [];
            foreach ($operations as $operation) {
                if ($operation instanceof DropTable) {
                    $deletions[] = sprintf('  Drop table "%s"', $operation->tableName);
                } elseif ($operation instanceof DropColumn) {
                    $deletions[] = sprintf('  Drop column "%s"."%s"', $operation->tableName, $operation->columnName);
                }
            }
            if ([] !== $deletions) {
                throw new \LogicException(
                    "Destructive operations require explicit approval (--allow-destructive):\n"
                        . implode("\n", $deletions),
                );
            }
        }

        $sql = implode("\n", new PostgresSqlGenerator()->generate($operations)) . "\n";

        return $this->history->append($head?->revision, $target, $name, $sql);
    }
}
