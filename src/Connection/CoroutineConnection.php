<?php
declare(strict_types=1);
namespace Spatial\Entity\Connection;

use Doctrine\DBAL\Driver\Connection;
class CoroutineConnection implements Connection
{
    private Connection $connection;

    public function __construct(Connection $connection)
    {
        $this->connection = $connection;
    }

    public function query(string $sql)
    {
        // Execute the query asynchronously if possible
        return $this->connection->query($sql);
    }

    public function prepare(string $sql)
    {
        return $this->connection->prepare($sql);
    }

    public function executeStatement(string $sql, array $params = [])
    {
        return $this->connection->executeStatement($sql, $params);
    }

    public function close(): void
    {
        $this->connection->close();
    }
}
