<?php

declare(strict_types=1);

namespace Spatial\Entity\Pool;

/**
 * Per-pool counters for the current worker process.
 *
 * OpenSwoole's ClientPool keeps size/num/active private with no accessors, so
 * lease accounting is tracked here instead. Read via {@see snapshot()} to
 * publish pool depth and checkout failures as telemetry — without it, a
 * draining pool is invisible until requests start timing out.
 */
final class PoolStats
{
    /** @var array<string, array<string, int>> */
    private static array $counters = [];

    private const METRICS = [
        'leased',      // successful checkouts
        'released',    // returns to the pool
        'timeouts',    // checkout gave up waiting
        'replaced',    // dead EM swapped for a fresh one
        'rolledBack',  // abandoned transaction rolled back on release
        'clearFailed', // clear() threw, EM was not reused
    ];

    public static function increment(string $poolId, string $metric): void
    {
        self::$counters[$poolId] ??= array_fill_keys(self::METRICS, 0);

        if (array_key_exists($metric, self::$counters[$poolId])) {
            self::$counters[$poolId][$metric]++;
        }
    }

    /**
     * In-flight leases for a pool: checkouts that have not been returned.
     * A value that only ever climbs is the signature of a lease leak.
     */
    public static function inUse(string $poolId): int
    {
        $counters = self::$counters[$poolId] ?? null;

        if ($counters === null) {
            return 0;
        }

        return max(0, $counters['leased'] - $counters['released']);
    }

    /**
     * @return array<string, array<string, int>> Counters per pool ID, plus `inUse`.
     */
    public static function snapshot(): array
    {
        $snapshot = [];

        foreach (self::$counters as $poolId => $counters) {
            $snapshot[$poolId] = $counters + ['inUse' => self::inUse($poolId)];
        }

        return $snapshot;
    }

    public static function reset(): void
    {
        self::$counters = [];
    }
}
