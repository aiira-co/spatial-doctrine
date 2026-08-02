<?php

declare(strict_types=1);

namespace Spatial\Entity;

use Doctrine\DBAL\Exception;
use Doctrine\ORM\EntityManagerInterface;
use OpenSwoole\Core\Coroutine\Pool\ClientPool;
use OpenSwoole\Coroutine as Co;
use Spatial\Entity\Connection\EntityManagerConfig;
use Spatial\Entity\Connection\EntityManagerFactory;
use Spatial\Entity\Driver\PgSQL\ConnectionPoolFactory;
use Spatial\Entity\Driver\PgSQL\Driver as SpatialPgDriver;
use Spatial\Entity\Driver\PgSQL\DriverMiddleware;
use Spatial\Entity\Driver\PgSQL\Scaler;
use Spatial\Entity\Exception\PoolExhaustedException;
use Spatial\Entity\Pool\PoolConfig;
use Spatial\Entity\Pool\PoolStats;
use Throwable;

use function defer;

/**
 * Worker-scoped pool of Doctrine EntityManagers, keyed by pool ID.
 *
 * A checkout is scoped to the coroutine that made it. The first
 * {@see getConnection()} in a coroutine leases an EntityManager and registers
 * a `defer` hook; every later call in the same coroutine gets that same
 * EntityManager back, and it returns to the pool when the coroutine ends —
 * which under the HTTP server means when the request finishes, whether it
 * returned early, threw, or completed.
 *
 * This replaces manual lease discipline. Pairing every checkout with a
 * {@see releaseConnection()} was unenforceable in practice: across the five
 * services, 652 handlers take an EntityManager and one wraps it in
 * try/finally, so any early return or exception used to strand a pool slot
 * permanently and eight of them starved the worker. {@see releaseConnection()}
 * is still accepted and still resets the EntityManager, but it is no longer
 * load-bearing.
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

    /**
     * Coroutine-scoped checkouts, keyed by pool ID within the coroutine's
     * context. Prefixed so it cannot collide with anything else stored there.
     */
    private const CONTEXT_KEY = 'spatial.entity.em.';

    /**
     * Driver classes that pool their own raw connections coroutine-side and so
     * need the pooling middleware installed. The OpsWay class is still
     * recognised for configs that predate the fork.
     *
     * @var list<string>
     */
    private const POOLED_PG_DRIVERS = [
        SpatialPgDriver::class,
        'OpsWay\Doctrine\DBAL\Swoole\PgSQL\Driver',
    ];

    /** @var array<string, Scaler> Downscalers, one per pool. */
    private static array $scaler = [];

    /**
     * One EntityManager per pool for callers with no coroutine to scope to.
     *
     * @var array<string, EntityManagerInterface>
     */
    private static array $standalone = [];

    /** @var array<string, \Spatial\Entity\Driver\PgSQL\ConnectionPoolInterface> Raw connection pools, one per pool. */
    private static array $driverPool = [];

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

            // Leading backslash trimmed because ::class never carries one but
            // configs conventionally write \Fully\Qualified\Name. Comparing the
            // two verbatim quietly skipped the middleware, and the driver then
            // failed every request with "Connection pool should be initialized".
            $driverClass = ltrim((string)($params['driverClass'] ?? ''), '\\');

            if ($driverClass !== '' && in_array($driverClass, self::POOLED_PG_DRIVERS, true)) {
                // Reuse the pool across instances of the same subclass. Built
                // per instance, a second SocialDB would stand up a second set
                // of raw connections that nothing ever drained.
                $pool = self::$driverPool[$this->poolId] ??= (new ConnectionPoolFactory())(
                    $params + ['poolId' => $this->poolId]
                );

                $doctrine->getDoctrineConfig()->setMiddlewares([
                    new DriverMiddleware($pool)
                ]);

                // Built here but deliberately not started: run() registers a
                // Timer, and this constructor runs in the master process before
                // the workers fork. Creating the event loop that early is what
                // made Process::signal break Server::start(). {@see warmup()}
                // starts it from onWorkerStart instead.
                self::$scaler[$this->poolId] ??= new Scaler(
                    $pool,
                    (int)($params['tickFrequency'] ?? 1000)
                );
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
     * Get this coroutine's EntityManager, leasing one if it has none yet.
     *
     * The first call in a coroutine takes a slot from the pool and arranges
     * for it to be returned when the coroutine ends. Repeat calls return the
     * same EntityManager, so a request that asks four times holds one slot,
     * not four, and sees one identity map rather than four.
     *
     * Outside a coroutine — console commands, queue consumers, migrations —
     * the pool is bypassed entirely and one EntityManager is kept for the
     * process. It cannot be used there: ClientPool leases through a Channel,
     * which OpenSwoole only allows inside a coroutine, so reaching for it threw
     * "API must be called in the coroutine". Nor is it needed, because without
     * coroutines there is nothing to interleave — a single EntityManager is
     * already the bound a pool of one would give.
     *
     * @throws PoolExhaustedException When no slot frees up in time.
     */
    public function getConnection(): EntityManagerInterface
    {
        $context = Co::getCid() > 0 ? Co::getContext() : null;

        if ($context === null) {
            $standalone = self::$standalone[$this->poolId] ?? null;

            if ($standalone instanceof EntityManagerInterface && $standalone->isOpen()) {
                return $standalone;
            }

            return self::$standalone[$this->poolId] = ($this->entityManager)();
        }

        $key  = self::CONTEXT_KEY . $this->poolId;
        $held = $context[$key] ?? null;

        if ($held instanceof EntityManagerInterface) {
            if ($held->isOpen()) {
                return $held;
            }

            // The slot is already ours, so replace the EntityManager in place
            // rather than taking a second one.
            $this->discard($held);
            PoolStats::increment($this->poolId, 'replaced');

            return $context[$key] = ($this->entityManager)();
        }

        $entityManager = $this->lease();
        $context[$key] = $entityManager;

        defer(fn() => $this->endCoroutineScope($key));

        return $entityManager;
    }

    /**
     * Take one EntityManager out of the pool, waiting up to the configured
     * checkout timeout.
     *
     * @throws PoolExhaustedException
     */
    private function lease(): EntityManagerInterface
    {
        $pool = self::$connectionPool[$this->poolId]
            ?? throw new \RuntimeException("Connection pool {$this->poolId} is not initialized.");

        $config        = self::$poolConfig[$this->poolId];
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
     * Return this coroutine's EntityManager to the pool. Runs from `defer`, so
     * it happens however the coroutine ended.
     */
    private function endCoroutineScope(string $key): void
    {
        $context = Co::getCid() > 0 ? Co::getContext() : null;

        if ($context === null) {
            return;
        }

        $entityManager = $context[$key] ?? null;
        unset($context[$key]);

        if ($entityManager instanceof EntityManagerInterface) {
            $this->returnToPool($entityManager);
        }
    }

    /**
     * Run $work with this coroutine's EntityManager, resetting it afterwards.
     *
     * The slot itself is returned when the coroutine ends, so this is now a
     * convenience rather than the only safe way to take a checkout.
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
     * Reset an EntityManager the caller has finished with.
     *
     * Kept for the hundreds of existing call sites, but no longer the thing
     * that returns the slot: the coroutine's `defer` hook does that, so a
     * handler that never reaches its release call no longer strands a slot.
     *
     * Either way this rolls back any abandoned transaction and clears the
     * identity map, leaving the EntityManager usable for the next caller.
     */
    public function releaseConnection(EntityManagerInterface $connection): void
    {
        if (!isset(self::$connectionPool[$this->poolId])) {
            return;
        }

        $context = Co::getCid() > 0 ? Co::getContext() : null;

        if ($context === null) {
            // No coroutine, so this came from the per-process EntityManager and
            // there is no pool slot to give back. Returning it would push to a
            // Channel, which is illegal here.
            $this->reset($connection);

            return;
        }

        $key = self::CONTEXT_KEY . $this->poolId;

        if (($context[$key] ?? null) === $connection) {
            // Still the coroutine's own EntityManager. Reset it, but leave the
            // slot held until the coroutine ends — releasing it here would let
            // the same request keep using an EntityManager another coroutine
            // had already taken.
            $this->reset($connection);

            return;
        }

        $this->returnToPool($connection);
    }

    /**
     * Put an EntityManager back, reset and safe for the next caller.
     *
     * Rolls back any transaction the caller abandoned and clears the identity
     * map — without the clear, a pooled EntityManager keeps every entity it
     * has ever loaded, which both grows unboundedly and lets a later request
     * read another request's entity instead of querying the database.
     */
    private function returnToPool(EntityManagerInterface $connection): void
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

        // Start the idle-connection downscalers here rather than in the
        // constructor: they register Timers, and the constructor runs in the
        // master process before the fork. Started from onWorkerStart, each
        // worker gets its own timer against its own pool.
        foreach (self::$scaler as $scaler) {
            $scaler->run();
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
        // Draining pops from a Channel, which OpenSwoole only permits inside a
        // coroutine, and onWorkerStop — the one place this is meant to be
        // called from — does not run in one. Called bare it raised "API must be
        // called in the coroutine" and killed the worker mid-shutdown, so the
        // pools were never drained and the timers below never stopped.
        if (Co::getCid() > 0) {
            self::drainAll();
        } else {
            Co::run(static fn() => self::drainAll());
        }

        // Stop the downscale timers before dropping the raw connections they
        // poll, or the tick fires against a closed pool during shutdown.
        foreach (self::$scaler as $scaler) {
            $scaler->close();
        }
        self::$scaler = [];

        foreach (self::$driverPool as $pool) {
            $pool->close();
        }
        self::$driverPool = [];
    }

    /** Drain every pool. Must run inside a coroutine. */
    private static function drainAll(): void
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
