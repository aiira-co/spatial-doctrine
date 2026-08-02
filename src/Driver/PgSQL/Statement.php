<?php

declare(strict_types=1);

namespace Spatial\Entity\Driver\PgSQL;

use Doctrine\DBAL\Driver\Result as ResultInterface;
use Doctrine\DBAL\Driver\Statement as StatementInterface;
use Doctrine\DBAL\ParameterType;
use OpenSwoole\Coroutine\PostgreSQL;
use OpenSwoole\Coroutine\PostgreSQLStatement;
use Spatial\Entity\Driver\PgSQL\Exception\DriverException as SwooleDriverException;

use function array_values;
use function is_array;
use function is_bool;
use function is_resource;
use function ksort;

/**
 * A server-side prepared statement.
 *
 * OpenSwoole hands back a PostgreSQLStatement object from prepare() and the
 * statement executes itself. Earlier drivers named each statement with a
 * uniqid and called execute() on the connection; that API no longer exists,
 * and it also left one un-deallocated prepared statement per query behind on
 * the server. Here the extension owns the statement's lifetime.
 */
final class Statement implements StatementInterface
{
    private PostgreSQLStatement $statement;

    /** @var array<int|string, string|null> */
    private array $params = [];

    public function __construct(private PostgreSQL $connection, string $sql, private ?ConnectionStats $stats)
    {
        $prepared = $this->connection->prepare($sql);

        if (! $prepared instanceof PostgreSQLStatement) {
            throw SwooleDriverException::fromConnection($this->connection);
        }

        $this->statement = $prepared;
    }

    /**
     * {@inheritdoc}
     *
     * @param int|string $param
     * @param mixed      $value
     * @param int        $type
     */
    public function bindValue($param, $value, $type = ParameterType::STRING) : bool
    {
        $this->params[$param] = $this->escapeValue($value, $type);

        return true;
    }

    /**
     * {@inheritdoc}
     *
     * @param int|string $param
     * @param mixed      $variable
     * @param int        $type
     * @param int|null   $length
     */
    public function bindParam($param, &$variable, $type = ParameterType::STRING, $length = null) : bool
    {
        return $this->bindValue($param, $variable, $type);
    }

    /**
     * @param mixed|null $params
     * @throws SwooleDriverException
     */
    public function execute($params = []) : ResultInterface
    {
        $mergedParams = $this->params;
        if (! empty($params)) {
            $params = is_array($params) ? $params : [$params];
            /** @psalm-var mixed|null $param */
            foreach ($params as $key => $param) {
                $mergedParams[$key] = $this->escapeValue($param);
            }
        }

        // Postgres binds $1, $2 ... positionally, so the values have to arrive
        // in order however Doctrine happened to key them.
        ksort($mergedParams);

        if ($this->statement->execute(array_values($mergedParams)) === false) {
            throw SwooleDriverException::fromConnection($this->connection);
        }

        if ($this->stats instanceof ConnectionStats) {
            $this->stats->counter++;
        }

        return new Result($this->statement);
    }

    public function errorCode() : int
    {
        return (int) $this->connection->errCode;
    }

    public function errorInfo() : string
    {
        return (string) $this->connection->error;
    }

    private function escapeValue(mixed $value, int $type = ParameterType::STRING) : ?string
    {
        if ($value !== null && (is_bool($value) || $type === ParameterType::BOOLEAN)) {
            return $value ? 'TRUE' : 'FALSE';
        }

        if ($value === null || $type === ParameterType::NULL) {
            return null;
        }

        if ($type === ParameterType::LARGE_OBJECT || is_resource($value)) {
            throw new SwooleDriverException(
                'Binary and large-object parameters are not supported by the Swoole PostgreSQL driver. '
                . 'Encode the value (for example base64 or hex) before binding it.'
            );
        }

        return (string) $value;
    }
}
