<?php

declare(strict_types=1);

namespace Spatial\Entity\Telemetry\Dbal;

use Doctrine\DBAL\Driver as DriverInterface;
use Doctrine\DBAL\Driver\Middleware as MiddlewareInterface;
use OpenTelemetry\API\Globals;
use OpenTelemetry\API\Trace\TracerInterface;

/**
 * DBAL driver middleware that records one CLIENT span per query.
 *
 * Installed for every connection pool. When the SDK is not configured the
 * tracer is a no-op, so there is no overhead beyond a pair of interface
 * calls on the hot path.
 */
final class OpenTelemetryMiddleware implements MiddlewareInterface
{
    private readonly TracerInterface $tracer;

    public function __construct(?TracerInterface $tracer = null)
    {
        $this->tracer = $tracer ?? Globals::tracerProvider()->getTracer('spatial.doctrine');
    }

    public function wrap(DriverInterface $driver): DriverInterface
    {
        return new Driver($driver, $this->tracer);
    }
}
