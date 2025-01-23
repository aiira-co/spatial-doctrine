<?php

declare(strict_types=1);

namespace Spatial\Entity;

use Doctrine\ORM\EntityManagerInterface;
use OpsWay\Doctrine\DBAL\Swoole\PgSQL\ConnectionPoolFactory;
use OpenSwoole\ClientPool;
use Doctrine\DBAL\Exception;
use OpsWay\Doctrine\DBAL\Swoole\PgSQL\DriverMiddleware;
use OpsWay\Doctrine\DBAL\Swoole\PgSQL\Scaler;

abstract class DbConnection
{
    /**
     * Static connection pools mapped by pool ID.
     *
     * @var ClientPool[]
     */
    protected static array $connectionPool = [];

    /**
     * Closure for creating EntityManager instances.
     */
    public \Closure $entityManager;

    /**
     * PostgreSQL driver for OpsWay.
     */
    private string $opsWayPostgresDriver = \OpsWay\Doctrine\DBAL\Swoole\PgSQL\Driver::class;

    /**
     * Pool ID for identifying the connection pool.
     */
    private string $poolId;

    public function __construct(string $poolId)
    {
        $this->poolId = $poolId;

        // Initialize connection pool if it doesn't exist
        if (!isset(self::$connectionPool[$this->poolId])) {
            self::$connectionPool[$this->poolId] = $this->initializeConnectionPool();
        }
    }

    /**
     * Initialize the connection pool.
     *
     * @return ClientPool
     */
    private function initializeConnectionPool(): ClientPool
    {
        $poolSize = 10; // Default pool size, make configurable if needed

        return new ClientPool(
            size: $poolSize,
            factory: function () {
                return ($this->entityManager)();
            },
            destructor: function ($connection) {
                unset($connection); // Clean up released connections
            }
        );
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

            // Configure OpsWay PostgreSQL Driver with Middleware
            if (isset($params['driverClass']) && $params['driverClass'] === $this->opsWayPostgresDriver) {
                $pool = (new ConnectionPoolFactory())($params);

                $doctrine->getDoctrineConfig()->setMiddlewares([
                    new DriverMiddleware($pool)
                ]);

                new Scaler($pool, $params['tickFrequency'] ?? 1000); // Default tick frequency
            }

            // Closure to create EntityManager
            $this->entityManager = fn() => $doctrine->entityManager($params);

            return ($this->entityManager)();
        } catch (Exception $e) {
            throw new \RuntimeException("Database connection failed for pool ID {$this->poolId}: " . $e->getMessage(), 0, $e);
        }
    }

    /**
     * Acquire a connection from the pool.
     *
     * @return EntityManagerInterface
     */
    public function getConnection(): EntityManagerInterface
    {
        if (!isset(self::$connectionPool[$this->poolId])) {
            throw new \RuntimeException("Connection pool {$this->poolId} is not initialized.");
        }

        $connection = self::$connectionPool[$this->poolId]->get();
        if (!$connection) {
            throw new \RuntimeException("Failed to acquire a connection from the pool {$this->poolId}.");
        }

        return $connection;
    }

    /**
     * Release a connection back to the pool.
     *
     * @param EntityManagerInterface $connection
     */
    public function releaseConnection(EntityManagerInterface $connection): void
    {
        if (isset(self::$connectionPool[$this->poolId])) {
            self::$connectionPool[$this->poolId]->put($connection);
        }
    }
}
