<?php

declare(strict_types=1);

namespace Ultra\EventStore;

final readonly class Checkpoint
{
    public Sequence $gapFloor;

    /** @var list<Sequence> */
    public array $gapSeqNos;

    /**
     * @param array<array-key, mixed> $gapSeqNos Sequence values are checked, sorted and deduplicated.
     */
    public function __construct(Sequence $gapFloor, array $gapSeqNos)
    {
        $this->gapFloor = $gapFloor;
        $this->gapSeqNos = self::normalizeGapSeqNos($gapFloor, $gapSeqNos);
    }

    public static function beginning(): self
    {
        return new self(Sequence::beginning(), []);
    }

    public static function at(Sequence $sequence): self
    {
        return new self($sequence, []);
    }

    public function serialize(): string
    {
        return json_encode([
            'gapFloor' => $this->gapFloor->value,
            'gapSeqNos' => $this->gapSeqNoValues(),
        ], JSON_THROW_ON_ERROR);
    }

    public static function deserialize(string $serialized): self
    {
        $data = json_decode($serialized, true, 512, JSON_THROW_ON_ERROR);
        if (!is_array($data)) {
            throw new \InvalidArgumentException('Checkpoint must deserialize to an object.');
        }

        $gapFloor = $data['gapFloor'] ?? null;
        $gapSeqNos = $data['gapSeqNos'] ?? null;

        if (!is_int($gapFloor) || !is_array($gapSeqNos)) {
            throw new \InvalidArgumentException('Checkpoint payload is missing gapFloor or gapSeqNos.');
        }

        return new self(new Sequence($gapFloor), array_map(static function (mixed $sequence): Sequence {
            if (!is_int($sequence)) {
                throw new \InvalidArgumentException('Checkpoint gap sequence numbers must be integers.');
            }

            return new Sequence($sequence);
        }, array_values($gapSeqNos)));
    }

    public function record(Sequence $sequence, bool $inGap): self
    {
        if ($sequence->isAtOrBefore($this->gapFloor) || $this->containsGapSeqNo($sequence)) {
            return $this;
        }

        if ($inGap) {
            return new self($this->gapFloor, [...$this->gapSeqNos, $sequence]);
        }

        return new self($sequence, $this->gapSeqNos);
    }

    public function contains(Sequence $sequence): bool
    {
        return $sequence->isAtOrBefore($this->gapFloor) || $this->containsGapSeqNo($sequence);
    }

    /**
     * @return list<int>
     */
    public function gapSeqNoValues(): array
    {
        return array_map(static fn(Sequence $sequence): int => $sequence->value, $this->gapSeqNos);
    }

    public function isSimplePosition(): bool
    {
        return [] === $this->gapSeqNos;
    }

    private function containsGapSeqNo(Sequence $sequence): bool
    {
        foreach ($this->gapSeqNos as $gapSeqNo) {
            if (0 === $sequence->compareTo($gapSeqNo)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param array<array-key, mixed> $gapSeqNos
     *
     * @return list<Sequence>
     */
    private static function normalizeGapSeqNos(Sequence $gapFloor, array $gapSeqNos): array
    {
        $values = [];
        foreach ($gapSeqNos as $sequence) {
            if (!$sequence instanceof Sequence) {
                throw new \InvalidArgumentException('Checkpoint gap sequence numbers must be Sequence instances.');
            }

            if ($sequence->isAtOrBefore($gapFloor)) {
                continue;
            }

            $values[$sequence->value] = $sequence;
        }

        ksort($values, SORT_NUMERIC);

        return array_values($values);
    }
}
