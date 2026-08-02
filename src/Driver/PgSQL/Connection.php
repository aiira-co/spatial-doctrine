<?php

declare(strict_types=1);

namespace Spatial\Entity\Driver\PgSQL;

use Closure;
use Doctrine\DBAL\Driver\Connection as ConnectionInterface;
use Doctrine\DBAL\Exception as DBALException;
use Doctrine\DBAL\ParameterType;
use OpenSwoole\Coroutine as Co;
use OpenSwoole\Coroutine\Context;
use OpenSwoole\Coroutine\PostgreSQL;
use OpenSwoole\Coroutine\PostgreSQLStatement;
use Spatial\Entity\Driver\PgSQL\Exception\ConnectionException;
use Spatial\Entity\Driver\PgSQL\Exception\DriverException;
use Spatial\Entity\Exception\PoolExhaustedException;
use Throwable;

use function defer;
use function strlen;
use function substr;
use function time;
use function trim;

final class Connection implements ConnectionInterface
{
    public function __construct(
        private ConnectionPoolInterface $pool,
        private int $retryDelay,
        private int $maxAttempts,
        private int $connectionDelay,
        private ?Closure $connectConstructor = null,
        private string $poolId = 'default',
        private int $poolSize = 0,
    ) {
    }

    /**
     * {@inheritdoc}
     *
     * @throws DriverException
     */
    public function prepare(string $sql) : Statement
    {
        $i        = 1;
        $posShift = 0;

        $phPos = SQLParserUtils::getPlaceholderPositions($sql);
        foreach ($phPos as $pos) {
            $placeholder = '$' . $i;
            $sql         = substr($sql, 0, (int) $pos + $posShift)
                . $placeholder
                . substr($sql, (int) $pos + $posShift + 1);
            $posShift   += strlen($placeholder) - 1;
            $i++;
        }
        $connection = $this->getNativeConnection();

        return new Statement($connection, $sql, $this->connectionStats());
    }

    /**
     * {@inheritdoc}
     */
    public function query(string $sql) : Result
    {
        return new Result($this->runQuery($sql));
    }

    /**
     * Run a statement and hand back the executed OpenSwoole statement object.
     *
     * query() returns PostgreSQLStatement on success and false on failure. It
     * used to be checked with is_resource(), which the object form never
     * satisfies, so every successful query was reported as a connection error.
     *
     * @throws ConnectionException
     */
    private function runQuery(string $sql) : PostgreSQLStatement
    {
        $connection = $this->getNativeConnection();
        $statement  = $connection->query($sql);

        $stats = $this->connectionStats();
        if ($stats instanceof ConnectionStats) {
            $stats->counter++;
        }

        if (! $statement instanceof PostgreSQLStatement) {
            throw ConnectionException::fromConnection($connection);
        }

        return $statement;
    }

    /**
     * {@inheritdoc}
     *
     * @param mixed $value
     * @param int   $type
     */
    public function quote($value, $type = ParameterType::STRING) : string
    {
        return "'" . (string) $this->getNativeConnection()->escape($value) . "'";
    }

    /**
     * {@inheritdoc}
     */
    public function exec(string $sql) : int
    {
        return (int) $this->runQuery($sql)->affectedRows();
    }

    /**
     * {@inheritdoc}
     *
     * @param string|null $name
     */
    public function lastInsertId($name = null) : string
    {
        $result = ! empty($name)
            ? $this->query('SELECT CURRVAL(\'' . $name . '\')')
            : $this->query('SELECT LASTVAL()');

        return (string) $result->fetchOne();
    }

    /**
     * {@inheritdoc}
     */
    public function beginTransaction() : bool
    {
        return $this->executeTransactionControl('START TRANSACTION');
    }

    /**
     * {@inheritdoc}
     */
    public function commit() : bool
    {
        return $this->executeTransactionControl('COMMIT');
    }

    /**
     * {@inheritdoc}
     */
    public function rollBack() : bool
    {
        return $this->executeTransactionControl('ROLLBACK');
    }

    /**
     * Run a transaction control statement and insist that it worked.
     *
     * These used to return true unconditionally without looking at the result,
     * so a COMMIT rejected by the server — a serialization failure, or a
     * transaction already aborted — reported success and the caller went on
     * believing its writes were durable.
     *
     * @throws ConnectionException
     */
    private function executeTransactionControl(string $sql) : bool
    {
        $this->runQuery($sql);

        return true;
    }

    public function errorCode() : int
    {
        return (int) $this->getNativeConnection()->errCode;
    }

    public function errorInfo() : string
    {
        return (string) $this->getNativeConnection()->error;
    }

    public function getNativeConnection() : PostgreSQL
    {
        $context = $this->getContext();
        /** @psalm-suppress MixedArrayAccess, MixedAssignment */
        [$connection] = $context[self::class] ?? [null, null];
        /** @psalm-suppress RedundantCondition */
        if (! $connection instanceof PostgreSQL) {
            $lastException = null;
            $exhausted     = false;
            for ($i = 0; $i < $this->maxAttempts; $i++) {
                try {
                    /**
                     * @psalm-suppress UnnecessaryVarAnnotation
                     * @psalm-var PostgreSQL      $connection
                     * @psalm-var ConnectionStats $stats
                     */
                    [$connection, $stats] = match (true) {
                        $this->connectConstructor === null => $this->pool->get($this->connectionDelay),
                        default                            => [($this->connectConstructor)(), new ConnectionStats(0, 0)]
                    };
                    if (! $connection instanceof PostgreSQL) {
                        // The pool waited connectionDelay seconds and nothing
                        // came free. That is saturation, not a broken server,
                        // and the two want different handling upstream.
                        $exhausted = true;

                        throw new DriverException('No connection available in pool');
                    }
                    $exhausted = false;
                    if (! $stats instanceof ConnectionStats) {
                        throw new DriverException('Provided connect is corrupted');
                    }
                    $ping         = $connection->query('SELECT 1');
                    $affectedRows = $ping instanceof PostgreSQLStatement ? (int) $ping->affectedRows() : 0;
                    if ($affectedRows !== 1) {
                        $reason = trim((string) $connection->error) ?: trim((string) $connection->errCode);
                        throw new ConnectionException(
                            "Connection ping failed. Trying reconnect (attempt $i). Reason: $reason"
                        );
                    }
                    $context[self::class] = [$connection, $stats];

                    /** @psalm-suppress UnusedFunctionCall */
                    defer($this->onDefer(...));

                    break;
                } catch (Throwable $e) {
                    $errCode = '';
                    if ($connection instanceof PostgreSQL) {
                        $errCode    = (int) $connection->errCode;
                        $connection = null;
                    }
                    $lastException = $e instanceof DBALException
                        ? $e
                        : new ConnectionException($e->getMessage(), (string) $errCode, '', (int) $e->getCode(), $e);
                    Co::usleep($this->retryDelay * 1000);  // Sleep mсs after failure
                }
            }
            if (! $connection instanceof PostgreSQL) {
                if ($exhausted) {
                    // Carries an HTTP status, so the caller answers 503 with a
                    // Retry-After instead of a 500 that looks like a bug.
                    throw new PoolExhaustedException(
                        poolId: $this->poolId,
                        waitedSeconds: (float) ($this->connectionDelay * $this->maxAttempts),
                        poolSize: $this->poolSize,
                        inUse: $this->pool->capacity() - $this->pool->length(),
                    );
                }

                $lastException instanceof Throwable
                    ? throw $lastException
                    : throw new ConnectionException('Connection could not be initiated');
            }
        }
        /** @psalm-suppress MixedArrayAccess, MixedAssignment */
        [$connection] = $context[self::class] ?? [null];

        /** @psalm-suppress RedundantCondition */
        if (! $connection instanceof PostgreSQL) {
            throw new ConnectionException('Connection in context storage is corrupted');
        }

        return $connection;
    }

    /** @psalm-suppress UnusedVariable, MixedArrayAccess, MixedAssignment */
    public function connectionStats() : ?ConnectionStats
    {
        [$connection, $stats] = $this->getContext()[self::class] ?? [null, null];

        return $stats;
    }

    /** @psalm-suppress MixedReturnTypeCoercion */
    private function getContext() : Context
    {
        $context = Co::getContext((int) Co::getCid());
        if (! $context instanceof Context) {
            throw new ConnectionException('Connection Co::Context unavailable');
        }
        return $context;
    }

    private function onDefer() : void
    {
        if ($this->connectConstructor) {
            return;
        }
        $context = $this->getContext();
        /** @psalm-suppress MixedArrayAccess, MixedAssignment */
        [$connection, $stats] = $context[self::class] ?? [null, null];
        /** @psalm-suppress RedundantCondition */
        if (! $connection instanceof PostgreSQL) {
            return;
        }
        /** @psalm-suppress TypeDoesNotContainType */
        if ($stats instanceof ConnectionStats) {
            $stats->lastInteraction = time();
        }
        $this->pool->put($connection);
        unset($context[self::class]);
    }
}
