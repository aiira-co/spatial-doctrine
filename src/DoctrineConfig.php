<?php
declare(strict_types=1);
namespace Spatial\Entity;

use Doctrine\ORM\Configuration;
use Spatial\Entity\Connection\ConnectionPool;

class DoctrineConfig
{
    public static function createConfig(array $dbParams, int $poolSize): array
    {
        $config = new Configuration();

        $connectionPool = new ConnectionPool($poolSize, function () use ($dbParams) {
            return \Doctrine\DBAL\DriverManager::getConnection($dbParams);
        });

        return [
            'config' => $config,
            'connection_pool' => $connectionPool,
        ];
    }
}
