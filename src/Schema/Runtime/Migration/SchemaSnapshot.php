<?php

declare(strict_types=1);

namespace Ultra\Schema\Migration;

use Ultra\Schema\SchemaDefinition;

/** @internal */
final readonly class SchemaSnapshot
{
    private const int FORMAT = 3;

    public string $revision;

    public function __construct(
        string $id,
        public SchemaDefinition $schema,
    ) {
        $this->revision =
            $id . ':' . hash('sha256', json_encode(SchemaDefinitionData::encode($schema), JSON_THROW_ON_ERROR));
    }

    public function toJson(): string
    {
        return json_encode(
            [
                'revision' => $this->revision,
                'format' => self::FORMAT,
                ...SchemaDefinitionData::encode($this->schema),
            ],
            JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
        ) . "\n";
    }

    public static function fromJson(string $json): self
    {
        $data = json_decode($json, flags: JSON_THROW_ON_ERROR);
        if (!$data instanceof \stdClass || self::FORMAT !== ($data->format ?? null)) {
            throw new \UnexpectedValueException('Unsupported schema snapshot format.');
        }

        $revision = $data->revision ?? null;
        $matches = [];
        if (
            !is_string($revision)
            || !preg_match('/\A([0-9]{6}-[a-z0-9]+(?:-[a-z0-9]+)*):[a-f0-9]{64}\z/', $revision, $matches)
        ) {
            throw new \UnexpectedValueException('Invalid schema snapshot revision.');
        }
        $snapshot = new self($matches[1], SchemaDefinitionData::decode($data));
        if ($snapshot->revision !== $revision) {
            throw new \UnexpectedValueException('Schema snapshot revision does not match its contents.');
        }

        return $snapshot;
    }
}
