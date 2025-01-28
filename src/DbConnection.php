<?php

declare(strict_types=1);

namespace Spatial\Entity;

use Doctrine\ORM\EntityManagerInterface;
use OpsWay\Doctrine\DBAL\Swoole\PgSQL\ConnectionPoolFactory;
use OpenSwoole\Core\Coroutine\Pool\ClientPool;
use Doctrine\DBAL\Exception;
use OpsWay\Doctrine\DBAL\Swoole\PgSQL\DriverMiddleware;
use OpsWay\Doctrine\DBAL\Swoole\PgSQL\Scaler;
use Spatial\Entity\Connection\EntityManagerConfig;
use Spatial\Entity\Connection\EntityManagerFactory;

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
    protected \Closure $entityManager;

    /**
     * PostgreSQL driver for OpsWay.
     */
    private string $opsWayPostgresDriver = \OpsWay\Doctrine\DBAL\Swoole\PgSQL\Driver::class;

    /**
     * Pool ID for identifying the connection pool.
     */
    private string $poolId;

    public function __construct(string $poolId, string $domain, array $params)
    {
        $this->poolId = $poolId;

        // Initialize connection pool if it doesn't exist
        if (!isset(self::$connectionPool[$this->poolId])) {
            $this->connect($domain, $params);
            self::$connectionPool[$this->poolId] = $this->initializeConnectionPool($domain, $params);
        }
    }

    /**
     * Initialize the connection pool.
     *
     * @return ClientPool
     */
    private function initializeConnectionPool(string $domain, array $params): ClientPool
    {
        $poolSize = 10; // Default pool size, make configurable if needed

        return new ClientPool(
            size: $poolSize,
            factory: EntityManagerFactory::class,
            config: new EntityManagerConfig($this->entityManager, $domain, $params)
        );
    }

    /**
     * Establish a connection to the database and set the EntityManager closure.
     *
     * @param string $domain The domain for configuration.
     * @param array $params The connection parameters.
     * @return void
     * @throws \RuntimeException
     */
    protected function connect(string $domain, array $params): void
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

        } catch (Exception $e) {
            throw new \RuntimeException("Database connection failed for pool ID {$this->poolId}: " . $e->getMessage(), 0, $e);
        }
    }

    /**
     * Acquire a connection from the pool.
     *
     * @return EntityManagerInterface
     * @throws \RuntimeException
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
     * @return \Closure
     */
    public function getEntityManager(): \Closure
    {
        return $this->entityManager;
    }

    /**
     * Release a connection back to the pool.
     *
     * @param EntityManagerInterface $connection
     * @return void
     */
    public function releaseConnection(EntityManagerInterface $connection): void
    {
        if (isset(self::$connectionPool[$this->poolId])) {
            self::$connectionPool[$this->poolId]->put($connection);
        }
    }
}
