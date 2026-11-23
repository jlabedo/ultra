<?php

declare(strict_types=1);

namespace Ultra\MappedTable\Buffer;

use Ultra\MappedTable\MappedKey;
use Ultra\UnitOfWork\Exception\ConcurrencyException;

/** @internal */
final class BufferedMembership
{
    /** @var list<MappedKey>|null */
    public ?array $expected = null;

    /** @var list<MappedKey> */
    public array $ignored = [];

    /** @var array<string, MappedKey>|null */
    public ?array $reconciled = null;

    public function __construct(
        public readonly ?MappedKey $owner,
    ) {}

    /**
     * @param list<MappedKey> $keys
     * @param list<BufferedRowUpdate> $updates
     */
    public function expect(array $keys, array $updates): void
    {
        $expected = [];
        foreach ($keys as $key) {
            $fingerprint = $key->fingerprint();
            if (isset($expected[$fingerprint])) {
                throw new \InvalidArgumentException('Duplicate expected row key.');
            }
            $expected[$fingerprint] = $key;
        }
        ksort($expected, SORT_STRING);

        $retained = $this->retained($updates);
        if (null !== $retained) {
            if (array_keys($retained) !== array_keys($expected)) {
                throw new ConcurrencyException(
                    'Row-set key expectation differs from the reconciled rows in this unit of work.',
                );
            }

            return;
        }

        $written = [];
        foreach ($updates as $update) {
            if (!$update->writes()) {
                continue;
            }

            $fingerprint = $update->key->fingerprint();
            $written[$fingerprint] = $update->key;
            if ($update->isDeleted() === isset($expected[$fingerprint])) {
                throw new ConcurrencyException(
                    'Row-set key expectation differs from the rows written in this unit of work.',
                );
            }
        }
        $committed = array_diff_key($expected, $written);
        if (null === $this->expected) {
            ksort($written, SORT_STRING);
            $this->expected = array_values($committed);
            $this->ignored = array_values($written);

            return;
        }

        $previous = array_map(static fn(MappedKey $key): string => $key->fingerprint(), $this->expected);
        $ignored = array_map(static fn(MappedKey $key): string => $key->fingerprint(), $this->ignored);
        if (
            array_values(array_diff($previous, array_keys($written))) !== array_values(array_diff(
                array_keys($committed),
                $ignored,
            ))
        ) {
            throw new ConcurrencyException('Conflicting row-set key expectations were registered.');
        }
    }

    /**
     * @param iterable<BufferedRowUpdate> $updates
     * @return array<string, MappedKey>|null
     */
    public function retained(iterable $updates): ?array
    {
        if (null === $this->reconciled) {
            return null;
        }

        $keys = $this->reconciled;
        foreach ($updates as $update) {
            $fingerprint = $update->key->fingerprint();
            if ($update->isDeleted()) {
                unset($keys[$fingerprint]);
            } elseif ($update->hasWriteIntent()) {
                $keys[$fingerprint] = $update->key;
            }
        }
        ksort($keys, SORT_STRING);

        return $keys;
    }
}
