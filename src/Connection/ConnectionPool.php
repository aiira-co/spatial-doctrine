<?php
declare(strict_types=1);
namespace Spatial\Entity\Connection;

use Swoole\Coroutine\Channel;
use Doctrine\DBAL\Driver\Connection;

class ConnectionPool
{
    private Channel $pool;

    public function __construct(int $size, callable $connectionFactory)
    {
        $this->pool = new Channel($size);

        for ($i = 0; $i < $size; $i++) {
            $this->pool->push($connectionFactory());
        }
    }

    public function getConnection(): Connection
    {
        return $this->pool->pop();
    }

    public function releaseConnection(Connection $connection): void
    {
        $this->pool->push($connection);
    }
}
