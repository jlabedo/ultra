<?php

declare(strict_types=1);

namespace Ultra\Schema\Migration;

use Ultra\Schema\SchemaDefinition;

/** @internal */
final readonly class SchemaSnapshot
{
    private const int FORMAT = 1;

    public function __construct(
        public ?string $previous,
        public SchemaDefinition $schema,
    ) {}

    public function toJson(): string
    {
        return json_encode(
            [
                'format' => self::FORMAT,
                'previous' => $this->previous,
                ...SchemaDefinitionData::encode($this->schema),
            ],
            JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
        ) . "\n";
    }

    public static function fromJson(string $json): self
    {
        $snapshot = json_decode($json, flags: JSON_THROW_ON_ERROR);
        if (!$snapshot instanceof \stdClass || self::FORMAT !== ($snapshot->format ?? null)) {
            throw new \UnexpectedValueException('Unsupported schema snapshot format.');
        }

        $fields = get_object_vars($snapshot);
        $previous = $fields['previous'] ?? null;
        if (!array_key_exists('previous', $fields) || null !== $previous && !is_string($previous)) {
            throw new \UnexpectedValueException('Invalid schema snapshot field "previous".');
        }

        return new self($previous, SchemaDefinitionData::decode($snapshot));
    }
}
