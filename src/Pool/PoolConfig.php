<?php

declare(strict_types=1);

namespace Spatial\Entity\Pool;

/**
 * Pool sizing and checkout behaviour, read from a DBAL connection block.
 *
 * These keys already existed in every project's config/packages/doctrine.yaml
 * but were previously inert because DbConnection hardcoded its pool size and
 * never passed a checkout timeout.
 */
final class PoolConfig
{
    public const DEFAULT_SIZE = 8;
    public const DEFAULT_CHECKOUT_TIMEOUT = 5.0;

    private function __construct(
        /** Maximum EntityManagers held by this pool, per worker process. */
        public readonly int $size,
        /** Seconds a coroutine will wait for a free slot before failing. */
        public readonly float $checkoutTimeout,
        /**
         * Issue a `SELECT 1` on checkout. Catches connections dropped
         * server-side (idle timeouts, failovers, restarts) at the cost of one
         * round trip per checkout. Off by default; `isOpen()` is always checked.
         */
        public readonly bool $validateOnCheckout,
    ) {
    }

    /**
     * @param array<string, mixed> $params A single `doctrine.dbal.connections.*` block.
     */
    public static function fromConnectionParams(array $params): self
    {
        $size = (int)($params['poolSize'] ?? self::DEFAULT_SIZE);

        // `connectionDelay` is documented in doctrine.yaml as "time(seconds)
        // for waiting response from pool", which is exactly a checkout timeout.
        $timeout = (float)($params['connectionDelay'] ?? self::DEFAULT_CHECKOUT_TIMEOUT);

        return new self(
            size: max(1, $size),
            checkoutTimeout: $timeout > 0 ? $timeout : self::DEFAULT_CHECKOUT_TIMEOUT,
            validateOnCheckout: (bool)($params['validateOnCheckout'] ?? false),
        );
    }
}
