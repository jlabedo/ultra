<?php

declare(strict_types=1);

namespace Ultra\MappedTable;

use Ultra\MappedTable\Codec\ScalarCodec;

/** The physical columns exposed by one row buffer. */
final readonly class MappedRowSetDefinition
{
    /**
     * @param array<array-key, mixed> $columns An ordered list of physical column names, validated at compilation.
     * @param non-empty-list<string> $primaryKey
     * @param array<string, ColumnCodec|string> $codecs Column names mapped to codec instances or Symfony service IDs.
     * @param list<string> $ownerColumns
     * @param ?list<string> $nullable Known nullable columns when supplied by a schema.
     */
    public function __construct(
        public string $table,
        public array $columns,
        public array $primaryKey,
        public array $codecs = [],
        public array $ownerColumns = [],
        public ?MappedRowSetOwner $owner = null,
        public ?array $nullable = null,
    ) {}

    /**
     * @internal
     * @param array<string, class-string<ColumnCodec>> $codecServices Resolved service classes during Symfony compilation.
     */
    public function compile(array $codecServices = []): MappedRowSetMapping
    {
        if (!array_is_list($this->columns)) {
            throw new \InvalidArgumentException('Mapped columns must be a list of names.');
        }
        $columns = [];
        foreach ($this->columns as $name) {
            if (!is_string($name) || '' === $name || isset($columns[$name])) {
                throw new \InvalidArgumentException('Mapped columns require distinct, non-empty names.');
            }
            $codec = $this->codecs[$name] ?? null;
            if (is_string($codec)) {
                $codec = $codecServices[$codec] ?? throw new \InvalidArgumentException(sprintf(
                    'Unresolved codec service "%s" for mapped column "%s.%s".', $codec, $this->table, $name,
                ));
            }
            $nullable = null === $this->nullable ? null : in_array($name, $this->nullable, true);
            $columns[$name] = new MappedColumn($nullable, $codec ?? new ScalarCodec());
        }
        foreach ([...array_keys($this->codecs), ...($this->nullable ?? [])] as $name) {
            if (!isset($columns[$name])) {
                throw new \InvalidArgumentException(sprintf('Unknown column "%s" in mapping for "%s".', $name, $this->table));
            }
        }

        return new MappedRowSetMapping($this->table, $columns, $this->primaryKey, $this->ownerColumns, $this->owner);
    }
}
