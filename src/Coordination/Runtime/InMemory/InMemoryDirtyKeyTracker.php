<?php

declare(strict_types=1);

namespace Ultra\Coordination\InMemory;

use Ultra\Coordination\Tracking\DirtyKeyTracker;
use Ultra\Coordination\Tracking\PendingKey;

final class InMemoryDirtyKeyTracker implements DirtyKeyTracker
{
    /** @var array<array-key, array<array-key, int>> channel => key => counter; numeric strings become integer keys. */
    private array $rows = [];

    public function bump(string $channel, string $key): void
    {
        $this->rows[$channel][$key] = ($this->rows[$channel][$key] ?? 0) + 1;
    }

    public function pending(int $limit = 256): array
    {
        $all = [];
        foreach (array_keys($this->rows) as $channel) {
            array_push($all, ...$this->pendingForChannel((string) $channel, PHP_INT_MAX));
        }

        usort($all, static function (PendingKey $a, PendingKey $b): int {
            $counterOrder = $b->counter <=> $a->counter;
            if (0 !== $counterOrder) {
                return $counterOrder;
            }
            $channelOrder = $a->channel <=> $b->channel;
            if (0 !== $channelOrder) {
                return $channelOrder;
            }

            return $a->key <=> $b->key;
        });

        return array_slice($all, 0, $limit);
    }

    public function pendingForChannel(string $channel, int $limit = 256): array
    {
        $pending = [];
        foreach ($this->rows[$channel] ?? [] as $key => $counter) {
            if ($counter <= 0) {
                continue;
            }
            $pending[] = new PendingKey($channel, (string) $key, $counter);
        }

        usort($pending, static function (PendingKey $a, PendingKey $b): int {
            $counterOrder = $b->counter <=> $a->counter;
            if (0 !== $counterOrder) {
                return $counterOrder;
            }

            return $a->key <=> $b->key;
        });

        return array_slice($pending, 0, $limit);
    }

    public function drainSeen(string $channel, array $seen): int
    {
        $drained = 0;
        foreach ($seen as $key => $seenCounter) {
            $current = $this->rows[$channel][(string) $key] ?? 0;
            if ($current <= 0 || $seenCounter <= 0) {
                continue;
            }
            $this->rows[$channel][(string) $key] = max(0, $current - $seenCounter);
            ++$drained;
        }

        return $drained;
    }
}
