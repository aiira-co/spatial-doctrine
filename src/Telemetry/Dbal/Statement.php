<?php

declare(strict_types=1);

namespace Spatial\Entity\Telemetry\Dbal;

use Doctrine\DBAL\Driver\Middleware\AbstractStatementMiddleware;
use Doctrine\DBAL\Driver\Result as ResultInterface;
use Doctrine\DBAL\Driver\Statement as StatementInterface;
use OpenTelemetry\API\Trace\SpanKind;
use OpenTelemetry\API\Trace\StatusCode;
use OpenTelemetry\API\Trace\TracerInterface;
use Throwable;

/** @internal */
final class Statement extends AbstractStatementMiddleware
{
    public function __construct(
        StatementInterface $statement,
        private readonly TracerInterface $tracer,
        private readonly string $dbSystem,
        private readonly string $sql,
    ) {
        parent::__construct($statement);
    }

    /**
     * {@inheritDoc}
     */
    public function execute($params = null): ResultInterface
    {
        $operation = SqlAttributes::operation($this->sql);
        $span      = $this->tracer
            ->spanBuilder('db.' . strtolower($operation))
            ->setSpanKind(SpanKind::KIND_CLIENT)
            ->setAttribute('db.system', $this->dbSystem)
            ->setAttribute('db.operation', $operation)
            ->setAttribute('db.statement', SqlAttributes::statement($this->sql))
            ->startSpan();

        $scope = $span->activate();

        try {
            return parent::execute($params);
        } catch (Throwable $e) {
            $span->recordException($e);
            $span->setStatus(StatusCode::STATUS_ERROR, $e->getMessage());

            throw $e;
        } finally {
            $scope->detach();
            $span->end();
        }
    }
}
