<?php

declare(strict_types=1);

namespace Spatial\Entity;

use Doctrine\DBAL\Exception;
use Doctrine\ORM\EntityManagerInterface;
use OpenSwoole\Core\Coroutine\Pool\ClientPool;
use OpsWay\Doctrine\DBAL\Swoole\PgSQL\ConnectionPoolFactory;
use OpsWay\Doctrine\DBAL\Swoole\PgSQL\DriverMiddleware;
use OpsWay\Doctrine\DBAL\Swoole\PgSQL\Scaler;
use Spatial\Entity\Connection\EntityManagerConfig;
use Spatial\Entity\Connection\EntityManagerFactory;
use Spatial\Entity\Exception\PoolExhaustedException;
use Spatial\Entity\Pool\PoolConfig;
use Spatial\Entity\Pool\PoolStats;
use Throwable;

/**
 * Worker-scoped pool of Doctrine EntityManagers, keyed by pool ID.
 *
 * Lease discipline: every {@see getConnection()} must be paired with exactly
 * one {@see releaseConnection()}. Prefer {@see withEntityManager()}, which
 * pairs them for you — an unpaired checkout permanently removes a slot from
 * the pool, and enough of them will starve the worker.
 */
abstract class DbConnection
{
    /**
     * Pools shared by every subclass, keyed by pool ID. Subclasses that pass
     * the same pool ID intentionally share one pool.
     *
     * @var array<string, ClientPool>
     */
    protected static array $connectionPool = [];

    /** @var array<string, PoolConfig> */
    private static array $poolConfig = [];

    /** Creates a fresh EntityManager for this pool. */
    protected \Closure $entityManager;

    private string $poolId;

    private string $opsWayPostgresDriver = \OpsWay\Doctrine\DBAL\Swoole\PgSQL\Driver::class;

    /**
     * @param array<string, mixed> $params A `doctrine.dbal.connections.*` block.
     */
    public function __construct(string $poolId, string $domain, array $params)
    {
        $this->poolId = $poolId;

        // Always build the factory closure, even when the pool already exists.
        // Previously this sat inside the "pool not yet created" branch, so a
        // second instance of the same subclass left $entityManager unset and
        // getEntityManager() threw on access.
        $this->connect($domain, $params);

        if (!isset(self::$connectionPool[$poolId])) {
            self::$poolConfig[$poolId] = PoolConfig::fromConnectionParams($params);
            self::$connectionPool[$poolId] = $this->initializeConnectionPool($domain, $params);
        }
    }

    /**
     * Create the pool without populating it.
     *
     * The pool is deliberately left empty: App::boot() runs in the Swoole
     * master process before workers are forked, so filling here would build
     * EntityManagers pre-fork and hand every worker a copy of the same
     * objects. An empty Channel forks safely, and ClientPool::get() creates
     * EntityManagers on demand up to the configured size — inside the worker
     * that will actually use them. Call {@see warmup()} from onWorkerStart if
     * you want them built ahead of the first request.
     */
    private function initializeConnectionPool(string $domain, array $params): ClientPool
    {
        return new ClientPool(
            size: self::$poolConfig[$this->poolId]->size,
            factory: EntityManagerFactory::class,
            config: new EntityManagerConfig($this->entityManager, $domain, $params)
        );
    }

    /**
     * Build the EntityManager factory for this pool.
     *
     * @param array<string, mixed> $params
     * @throws \RuntimeException When Doctrine cannot be configured for $domain.
     */
    protected function connect(string $domain, array $params): void
    {
        try {
            $doctrine = new DoctrineEntity($domain);

            if (isset($params['driverClass']) && $params['driverClass'] === $this->opsWayPostgresDriver) {
                $pool = (new ConnectionPoolFactory())($params);

                $doctrine->getDoctrineConfig()->setMiddlewares([
                    new DriverMiddleware($pool)
                ]);

                new Scaler($pool, $params['tickFrequency'] ?? 1000);
            }

            $this->entityManager = fn(): EntityManagerInterface => $doctrine->entityManager($params);
        } catch (Exception $e) {
            throw new \RuntimeException(
                "Database connection failed for pool ID {$this->poolId}: " . $e->getMessage(),
                0,
                $e
            );
        }
    }

    /**
     * Lease an EntityManager from the pool.
     *
     * Waits at most `connectionDelay` seconds for a free slot, then throws
     * rather than blocking forever. A slot holding an unusable EntityManager
     * is swapped for a fresh one instead of being handed out or dropped.
     *
     * @throws PoolExhaustedException When no slot frees up in time.
     */
    public function getConnection(): EntityManagerInterface
    {
        $pool = self::$connectionPool[$this->poolId]
            ?? throw new \RuntimeException("Connection pool {$this->poolId} is not initialized.");

        $config = self::$poolConfig[$this->poolId];
        $entityManager = $pool->get($config->checkoutTimeout);

        if (!$entityManager instanceof EntityManagerInterface) {
            PoolStats::increment($this->poolId, 'timeouts');

            throw new PoolExhaustedException(
                poolId: $this->poolId,
                waitedSeconds: $config->checkoutTimeout,
                poolSize: $config->size,
                inUse: PoolStats::inUse($this->poolId),
            );
        }

        PoolStats::increment($this->poolId, 'leased');

        if ($this->isUsable($entityManager, $config->validateOnCheckout)) {
            return $entityManager;
        }

        // The slot held a dead EntityManager. Discard it and take a fresh one
        // in its place, keeping the lease balanced at one checkout.
        $this->discard($entityManager);
        PoolStats::increment($this->poolId, 'replaced');

        return ($this->entityManager)();
    }

    /**
     * Run $work with a leased EntityManager and always return it to the pool.
     *
     * @template T
     * @param callable(EntityManagerInterface): T $work
     * @return T
     * @throws PoolExhaustedException
     */
    public function withEntityManager(callable $work): mixed
    {
        $entityManager = $this->getConnection();

        try {
            return $work($entityManager);
        } finally {
            $this->releaseConnection($entityManager);
        }
    }

    /**
     * Return an EntityManager to the pool, reset and safe for the next caller.
     *
     * Rolls back any transaction the caller abandoned and clears the identity
     * map — without the clear, a pooled EntityManager keeps every entity it
     * has ever loaded, which both grows unboundedly and lets a later request
     * read another request's entity instead of querying the database.
     */
    public function releaseConnection(EntityManagerInterface $connection): void
    {
        $pool = self::$connectionPool[$this->poolId] ?? null;

        if ($pool === null) {
            return;
        }

        PoolStats::increment($this->poolId, 'released');

        if (!$this->reset($connection)) {
            // Unusable. Replace the slot so the pool keeps its configured size
            // instead of shrinking by one every time a request fails.
            $this->discard($connection);
            $pool->put(($this->entityManager)());

            return;
        }

        $pool->put($connection);
    }

    /**
     * Populate this worker's pool up to its configured size.
     *
     * Safe to call from onWorkerStart. Never call it before the workers are
     * forked; that is the situation the empty-pool default exists to avoid.
     */
    public static function warmup(): void
    {
        foreach (self::$connectionPool as $pool) {
            $pool->fill();
        }
    }

    /**
     * Per-pool lease counters for this worker, for publishing as telemetry.
     *
     * @return array<string, array<string, int>>
     */
    public static function stats(): array
    {
        return PoolStats::snapshot();
    }

    /**
     * The EntityManager factory, for callers that manage their own lifecycle
     * (console commands, migrations) rather than leasing from the pool.
     */
    public function getEntityManager(): \Closure
    {
        return $this->entityManager;
    }

    public function closeConnection(): void
    {
        $this->drain($this->poolId);
    }

    /**
     * Drain every pool. Call on worker shutdown so Postgres backends are
     * released promptly instead of waiting on server-side timeouts.
     */
    public static function closeAllConnection(): void
    {
        foreach (array_keys(self::$connectionPool) as $poolId) {
            self::drain($poolId);
        }
    }

    /**
     * Close the EntityManagers held by a pool.
     *
     * Deliberately avoids ClientPool::close(), whose drain loop waits on an
     * internal `active` counter with no upper bound — a single leaked lease
     * makes it spin forever, and a checkout timeout inflates it permanently.
     */
    private static function drain(string $poolId): void
    {
        $pool = self::$connectionPool[$poolId] ?? null;

        if ($pool === null) {
            return;
        }

        $remaining = self::$poolConfig[$poolId]->size ?? PoolConfig::DEFAULT_SIZE;

        while ($remaining-- > 0) {
            $entityManager = $pool->get(0.05);

            if (!$entityManager instanceof EntityManagerInterface) {
                break;
            }

            self::closeQuietly($entityManager);
        }
    }

    /**
     * Whether a leased EntityManager can be handed to a caller.
     */
    private function isUsable(EntityManagerInterface $entityManager, bool $ping): bool
    {
        if (!$entityManager->isOpen()) {
            return false;
        }

        if (!$ping) {
            return true;
        }

        try {
            $entityManager->getConnection()->executeQuery('SELECT 1');

            return true;
        } catch (Throwable) {
            return false;
        }
    }

    /**
     * Reset a returned EntityManager for reuse.
     *
     * @return bool False when it cannot be reused and must be replaced.
     */
    private function reset(EntityManagerInterface $entityManager): bool
    {
        if (!$entityManager->isOpen()) {
            return false;
        }

        try {
            $connection = $entityManager->getConnection();

            if ($connection->isTransactionActive()) {
                // The caller threw mid-transaction. Leaving it open would hold
                // Postgres locks and put the next borrower inside a doomed
                // transaction that fails on its first statement.
                $connection->rollBack();
                PoolStats::increment($this->poolId, 'rolledBack');
            }

            $entityManager->clear();

            return true;
        } catch (Throwable) {
            PoolStats::increment($this->poolId, 'clearFailed');

            return false;
        }
    }

    private function discard(EntityManagerInterface $entityManager): void
    {
        self::closeQuietly($entityManager);
    }

    private static function closeQuietly(EntityManagerInterface $entityManager): void
    {
        try {
            $entityManager->getConnection()->close();
        } catch (Throwable) {
            // Already gone; nothing to release.
        }
    }
}
