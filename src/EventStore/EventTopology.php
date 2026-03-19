<?php

declare(strict_types=1);

namespace Ultra\EventStore;

/**
 * Describes the lock/query topology of a single event type.
 *
 * `identifiers` lists the domain identifiers that may appear on the event. `dependencies` captures unary,
 * transitive implications between identifiers, for example `courseId => facultyId`. Canonicalization uses
 * those implications to drop identifiers that do not add locking precision.
 */
readonly class EventTopology
{
    /**
     * @param list<string>                $identifiers
     * @param array<string, list<string>> $dependencies determinant identifier => implied identifiers
     */
    public function __construct(
        public array $identifiers,
        public array $dependencies = [],
    ) {}

    /**
     * @param list<string> $dimensions
     */
    public function supportsAll(array $dimensions): bool
    {
        foreach ($dimensions as $dimension) {
            if (! in_array($dimension, $this->identifiers, true)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Canonicalize a set of identifiers by dropping identifiers implied by the others.
     *
     * @param list<string> $dimensions
     *
     * @return list<string>
     */
    public function canonicalize(array $dimensions): array
    {
        $dimensions = array_values(array_unique($dimensions));
        sort($dimensions);

        $canonical = [];
        foreach ($dimensions as $dimension) {
            $others = array_values(array_diff($dimensions, [$dimension]));
            if (in_array($dimension, $this->closure($others), true)) {
                continue;
            }
            $canonical[] = $dimension;
        }

        sort($canonical);

        return $canonical;
    }

    /**
     * @param list<string> $dimensions
     *
     * @return list<string>
     */
    private function closure(array $dimensions): array
    {
        $seen = array_fill_keys($dimensions, true);
        $changed = true;

        while ($changed) {
            $changed = false;
            foreach (array_keys($seen) as $determinant) {
                foreach ($this->dependencies[$determinant] ?? [] as $implied) {
                    if (isset($seen[$implied])) {
                        continue;
                    }
                    $seen[$implied] = true;
                    $changed = true;
                }
            }
        }

        $result = array_keys($seen);
        sort($result);

        return $result;
    }
}
