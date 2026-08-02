<?php

declare(strict_types=1);

namespace Spatial\Entity\Telemetry\Dbal;

use Doctrine\DBAL\Driver\Connection as ConnectionInterface;
use Doctrine\DBAL\Driver\Middleware\AbstractConnectionMiddleware;
use Doctrine\DBAL\Driver\Result;
use Doctrine\DBAL\Driver\Statement as DriverStatement;
use OpenTelemetry\API\Trace\SpanKind;
use OpenTelemetry\API\Trace\StatusCode;
use OpenTelemetry\API\Trace\TracerInterface;
use Throwable;

/** @internal */
final class Connection extends AbstractConnectionMiddleware
{
    public function __construct(
        ConnectionInterface $connection,
        private readonly TracerInterface $tracer,
        private readonly string $dbSystem,
    ) {
        parent::__construct($connection);
    }

    public function prepare(string $sql): DriverStatement
    {
        return new Statement(
            parent::prepare($sql),
            $this->tracer,
            $this->dbSystem,
            $sql,
        );
    }

    public function query(string $sql): Result
    {
        return $this->trace($sql, fn() => parent::query($sql));
    }

    public function exec(string $sql): int
    {
        return $this->trace($sql, fn() => parent::exec($sql));
    }

    /**
     * {@inheritDoc}
     */
    public function beginTransaction()
    {
        return $this->trace('BEGIN', fn() => parent::beginTransaction());
    }

    /**
     * {@inheritDoc}
     */
    public function commit()
    {
        return $this->trace('COMMIT', fn() => parent::commit());
    }

    /**
     * {@inheritDoc}
     */
    public function rollBack()
    {
        return $this->trace('ROLLBACK', fn() => parent::rollBack());
    }

    /**
     * @template T
     *
     * @param callable(): T $execute
     *
     * @return T
     */
    private function trace(string $sql, callable $execute): mixed
    {
        $operation = SqlAttributes::operation($sql);
        $span      = $this->tracer
            ->spanBuilder('db.' . strtolower($operation))
            ->setSpanKind(SpanKind::KIND_CLIENT)
            ->setAttribute('db.system', $this->dbSystem)
            ->setAttribute('db.operation', $operation)
            ->setAttribute('db.statement', SqlAttributes::statement($sql))
            ->startSpan();

        $scope = $span->activate();

        try {
            return $execute();
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
