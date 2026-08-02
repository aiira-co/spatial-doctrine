<?php

declare(strict_types=1);

namespace Spatial\Entity\Exception;

use RuntimeException;
use Spatial\Core\Exception\HttpAwareExceptionInterface;

/**
 * No EntityManager became available within the configured checkout timeout.
 *
 * This replaces the previous behaviour, where an exhausted pool blocked the
 * calling coroutine forever on an empty channel. Surfacing it as a 503 lets
 * load shedding, health checks and alerting all see the condition.
 */
class PoolExhaustedException extends RuntimeException implements HttpAwareExceptionInterface
{
    public function __construct(
        private readonly string $poolId,
        private readonly float $waitedSeconds,
        private readonly int $poolSize,
        private readonly int $inUse,
    ) {
        parent::__construct(
            sprintf(
                'Timed out after %.2fs waiting for a connection from pool "%s" (size %d, %d in use).',
                $waitedSeconds,
                $poolId,
                $poolSize,
                $inUse,
            )
        );
    }

    public function getStatusCode(): int
    {
        return 503;
    }

    public function getErrorTitle(): string
    {
        return 'Service Unavailable';
    }

    public function getRetryAfter(): ?int
    {
        return 1;
    }

    public function getPoolId(): string
    {
        return $this->poolId;
    }

    public function getWaitedSeconds(): float
    {
        return $this->waitedSeconds;
    }

    public function getPoolSize(): int
    {
        return $this->poolSize;
    }

    public function getInUse(): int
    {
        return $this->inUse;
    }
}
