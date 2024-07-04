<?php

declare(strict_types=1);

namespace Spatial\Entity;

use Doctrine\DBAL\Exception;
use Doctrine\ORM\EntityManagerInterface;
use OpsWay\Doctrine\DBAL\Swoole\PgSQL\Scaler;
use OpsWay\Doctrine\DBAL\Swoole\PgSQL\DriverMiddleware;
use OpsWay\Doctrine\DBAL\Swoole\PgSQL\ConnectionPoolFactory;

use OpsWay\Doctrine\ORM\Swoole\EntityManager;
    

abstract class DbConnection
{
    public \Closure $entityManager;
    private string $_opsWayPostgresDriver = '\OpsWay\Doctrine\DBAL\Swoole\PgSQL\Driver';
    
    public function __construct()
    {
//        $_ = $this->connect($domain, $params);
    }

    protected function connect(string $domain, array $params): EntityManagerInterface
    {
        try {
            $doctrine = new DoctrineEntity($domain);

            if (
                $params['driverClass'] === $this->_opsWayPostgresDriver
            ) {
                $pool = (new ConnectionPoolFactory())($params);
                $doctrine->getDoctrineConfig()
                    ->setMiddlewares(
                        [
                            new DriverMiddleware($pool)
                        ]
                    );

                $scaler = new Scaler(
                    $pool,
                    $params['tickFrequency']
                ); // will try to free idle connect on connectionTtl overdue
            }

            $this->entityManager = fn():\Doctrine\ORM\EntityManager => $doctrine->entityManager($params);

        } catch (Exception $e) {
            die($e->getMessage());
        }


        return new EntityManager($this->entityManager);
        //        $this->emSuite = $doctrine->entityManager($connectionParams);
    }
}