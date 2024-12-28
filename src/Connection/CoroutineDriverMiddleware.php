<?php
declare(strict_types=1);
namespace Spatial\Entity\Connection;

use Doctrine\DBAL\Driver\Middleware;

class CoroutineDriverMiddleware implements Middleware
{
    private ConnectionPool $connectionPool;

    public function __construct(ConnectionPool $pool)
    {
        $this->connectionPool = $pool;
    }

    public function wrap(Connection $connection): Connection
    {
        return new CoroutineConnection($connection);
    }
}
