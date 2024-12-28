<?php

declare(strict_types=1);

namespace Spatial\Entity;

use Doctrine\DBAL\Exception;
use Doctrine\ORM\EntityManagerInterface;
use OpsWay\Doctrine\DBAL\Swoole\PgSQL\Scaler;
use OpsWay\Doctrine\DBAL\Swoole\PgSQL\DriverMiddleware;
use OpsWay\Doctrine\DBAL\Swoole\PgSQL\ConnectionPoolFactory;

abstract class DbConnection
{
    public \Closure $entityManager;
    private string $opsWayPostgresDriver = \OpsWay\Doctrine\DBAL\Swoole\PgSQL\Driver::class;

    public function __construct()
    {
        // Initialization logic (if any) can go here.
    }

    /**
     * Establish a connection to the database and return the EntityManager.
     *
     * @param string $domain The domain for configuration.
     * @param array $params The connection parameters.
     * @return EntityManagerInterface
     * @throws \InvalidArgumentException
     */
    protected function connect(string $domain, array $params): EntityManagerInterface
    {
        try {
            $doctrine = new DoctrineEntity($domain);

            // Configure OpsWay PostgreSQL Driver
            if (isset($params['driverClass']) && $params['driverClass'] === $this->opsWayPostgresDriver) {
                $pool = (new ConnectionPoolFactory())($params);

                $doctrine->getDoctrineConfig()->setMiddlewares([
                    new DriverMiddleware($pool)
                ]);

                $scaler = new Scaler(
                    $pool,
                    $params['tickFrequency'] ?? 1000 // Default to 1000ms if not set
                );
            }

            // Closure to create the EntityManager
            $this->entityManager = function () use ($doctrine, $params): EntityManagerInterface {
                return $doctrine->entityManager($params);
            };

            // Return the EntityManager instance
            return ($this->entityManager)();

        } catch (Exception $e) {
            // Use proper error handling or rethrow the exception
            throw new \RuntimeException('Database connection failed: ' . $e->getMessage(), 0, $e);
        }
    }
}
