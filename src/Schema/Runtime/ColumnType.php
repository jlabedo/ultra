<?php

declare(strict_types=1);

namespace Ultra\Schema;

/** Common PostgreSQL type families. */
enum ColumnType: string
{
    case Text = 'text';
    case Varchar = 'varchar';
    case Int32 = 'integer';
    case Int64 = 'bigint';
    case Boolean = 'boolean';
    case Uuid = 'uuid';
    case Date = 'date';
    case Timestamp = 'timestamp';
    case TimestampWithTimeZone = 'timestamptz';
    case TextArray = 'text[]';
    case UuidArray = 'uuid[]';
    case IntArray = 'integer[]';
    case Jsonb = 'jsonb';

    public function sqlType(): SqlType
    {
        return SqlType::of($this);
    }
}
