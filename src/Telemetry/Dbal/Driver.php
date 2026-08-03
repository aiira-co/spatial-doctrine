<?php

declare(strict_types=1);

namespace Spatial\Entity\Telemetry\Dbal;

use Doctrine\DBAL\Driver as DriverInterface;
use Doctrine\DBAL\Driver\Connection as DriverConnection;
use Doctrine\DBAL\Driver\Middleware\AbstractDriverMiddleware;
use OpenTelemetry\API\Trace\TracerInterface;
use SensitiveParameter;

/** @internal */
final class Driver extends AbstractDriverMiddleware
{
    public function __construct(
        DriverInterface $driver,
        private readonly TracerInterface $tracer,
    ) {
        parent::__construct($driver);
    }

    /**
     * {@inheritDoc}
     */
    public function connect(
        #[SensitiveParameter]
        array $params
    ): DriverConnection {
        return new Connection(
            parent::connect($params),
            $this->tracer,
            (string)($params['driver'] ?? 'postgresql'),
        );
    }
}
