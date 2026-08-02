<?php

declare(strict_types=1);

/**
 * Regression tests for DbConnection lease accounting.
 *
 * Run: php tests/pool-lease-test.php   (no extensions or database required)
 *
 * The OpenSwoole extension is not required, so ClientPool and the
 * Doctrine collaborators are replaced with stubs. The stub pool reimplements
 * the real ClientPool contract exactly as vendored (lazy make while
 * num < size, Channel::pop returning false on timeout, put() with $isNew).
 * What is under test is DbConnection's own behaviour: whether a lease is
 * always balanced, whether a dead EntityManager can shrink the pool, and
 * whether an exhausted pool throws instead of blocking.
 */

/* ---------------------------------------------------------------- stubs */

namespace OpenSwoole {

    /**
     * Stands in for the scheduler so the pooling logic can be exercised on a
     * host with no OpenSwoole extension. Coroutines are emulated rather than
     * scheduled: `$cid` says which one is running, each gets its own context,
     * and `defer` callbacks are held until it is ended explicitly. That is
     * enough for DbConnection, which only asks which coroutine it is in and
     * for somewhere to hang cleanup.
     *
     * Real coroutines are covered by tests/coroutine-scope-test.php, which
     * runs in a container. Setting `$cid` to -1 gives the console-command and
     * queue-consumer path.
     */
    class Coroutine
    {
        public static int $cid = -1;

        /** @var array<int, \ArrayObject<string, mixed>> */
        public static array $contexts = [];

        /** @var array<int, list<callable>> */
        public static array $defers = [];

        public static function getCid(): int
        {
            return self::$cid;
        }

        public static function getContext(): ?object
        {
            if (self::$cid <= 0) {
                return null;
            }

            return self::$contexts[self::$cid] ??= new \ArrayObject();
        }

        public static function reset(): void
        {
            self::$cid      = -1;
            self::$contexts = [];
            self::$defers   = [];
        }
    }
}

namespace {
    function defer(callable $callback): void
    {
        $cid = OpenSwoole\Coroutine::$cid;

        if ($cid <= 0) {
            throw new \RuntimeException('defer() outside a coroutine');
        }

        OpenSwoole\Coroutine::$defers[$cid][] = $callback;
    }
}

namespace OpenSwoole\Core\Coroutine\Client {
    interface ClientConfigInterface
    {
    }

    interface ClientFactoryInterface
    {
    }
}

namespace OpenSwoole\Core\Coroutine\Pool {

    class ClientPool
    {
        public const DEFAULT_SIZE = 16;

        /** @var array<int, mixed> */
        private array $queue = [];
        private int $size;
        private int $num = 0;
        private int $active = 0;
        private $factory;
        private $config;

        public function __construct($factory, $config, int $size = self::DEFAULT_SIZE, bool $heartbeat = false)
        {
            $this->factory = $factory;
            $this->config = $config;
            $this->size = $size;
        }

        public function fill(): void
        {
            while ($this->size > $this->num) {
                $this->make();
            }
        }

        /** Returns false when nothing is available, mirroring Channel::pop timeout. */
        public function get(float $timeout = -1)
        {
            if ($this->queue === [] && $this->num < $this->size) {
                $this->make();
            }

            $this->active++;

            if ($this->queue === []) {
                return false;
            }

            return array_shift($this->queue);
        }

        public function put($connection, $isNew = false): void
        {
            if ($connection !== null) {
                $this->queue[] = $connection;

                if (!$isNew) {
                    $this->active--;
                }
            } else {
                $this->num -= 1;
                $this->make();
            }
        }

        protected function make(): void
        {
            $this->num++;
            $this->put(($this->factory)::make($this->config), true);
        }

        /* ---- test introspection, not part of the real class ---- */
        public function available(): int
        {
            return count($this->queue);
        }

        public function created(): int
        {
            return $this->num;
        }

        public function capacity(): int
        {
            return $this->size;
        }
    }
}

namespace Doctrine\DBAL {
    class Exception extends \Exception
    {
    }

    class Connection
    {
        public bool $closed = false;
        public bool $inTransaction = false;
        public int $rollBacks = 0;

        public function isTransactionActive(): bool
        {
            return $this->inTransaction;
        }

        public function rollBack(): void
        {
            $this->inTransaction = false;
            $this->rollBacks++;
        }

        public function close(): void
        {
            $this->closed = true;
        }

        public function executeQuery(string $sql)
        {
            if ($this->closed) {
                throw new Exception('connection gone');
            }

            return true;
        }
    }
}

namespace Doctrine\ORM {
    interface EntityManagerInterface
    {
        public function isOpen(): bool;

        public function clear(): void;

        public function getConnection(): \Doctrine\DBAL\Connection;
    }
}

namespace Spatial\Entity\Test {

    use Doctrine\ORM\EntityManagerInterface;

    class FakeEntityManager implements EntityManagerInterface
    {
        public static int $created = 0;
        public int $id;
        public int $clears = 0;

        public function __construct(
            public bool $open = true,
            public bool $clearThrows = false,
            private \Doctrine\DBAL\Connection $connection = new \Doctrine\DBAL\Connection(),
        ) {
            $this->id = ++self::$created;
        }

        public function isOpen(): bool
        {
            return $this->open;
        }

        public function clear(): void
        {
            if ($this->clearThrows) {
                throw new \RuntimeException('clear failed');
            }

            $this->clears++;
        }

        public function getConnection(): \Doctrine\DBAL\Connection
        {
            return $this->connection;
        }
    }
}

/* ------------------------------------------------------- real code under test */

namespace {

    // spatial/core sits beside this package in the workspace and under
    // vendor/spatial/core once installed.
    (static function (): void {
        foreach (['/../../spatial-core', '/../../core'] as $base) {
            $path = __DIR__ . $base . '/src/core/Exception/HttpAwareExceptionInterface.php';
            if (is_file($path)) {
                require $path;

                return;
            }
        }
        fwrite(STDERR, "Could not locate spatial/core's HttpAwareExceptionInterface.\n");
        exit(1);
    })();
    require __DIR__ . '/../src/Connection/EntityManagerConfig.php';
    require __DIR__ . '/../src/Connection/EntityManagerFactory.php';
    require __DIR__ . '/../src/Pool/PoolConfig.php';
    require __DIR__ . '/../src/Pool/PoolStats.php';
    require __DIR__ . '/../src/Exception/PoolExhaustedException.php';
    require __DIR__ . '/../src/DbConnection.php';

    use Doctrine\ORM\EntityManagerInterface;
    use OpenSwoole\Coroutine;
    use Spatial\Entity\DbConnection;
    use Spatial\Entity\Exception\PoolExhaustedException;
    use Spatial\Entity\Pool\PoolStats;
    use Spatial\Entity\Test\FakeEntityManager;

    /**
     * Test double: bypasses DoctrineEntity so no database or config constant
     * is required. Everything else is the production DbConnection.
     */
    final class TestDb extends DbConnection
    {
        /** @var callable():EntityManagerInterface */
        public static $maker;

        protected function connect(string $domain, array $params): void
        {
            $this->entityManager = static fn(): EntityManagerInterface => (static::$maker)();
        }

        public static function pool(string $poolId): object
        {
            return self::$connectionPool[$poolId];
        }

        public static function forget(): void
        {
            self::$connectionPool = [];
            PoolStats::reset();
        }
    }

    $pass = 0;
    $fail = 0;

    function check(string $label, bool $ok, string $detail = ''): void
    {
        global $pass, $fail;
        $ok ? $pass++ : $fail++;
        printf("%s  %s%s\n", $ok ? 'ok  ' : 'FAIL', $label, $ok || $detail === '' ? '' : "  ({$detail})");
    }

    /** Run $work as coroutine $cid, without ending it. */
    function asCoroutine(int $cid, callable $work): mixed
    {
        $previous = Coroutine::$cid;
        Coroutine::$cid = $cid;

        try {
            return $work();
        } finally {
            Coroutine::$cid = $previous;
        }
    }

    /** End coroutine $cid, running its defer hooks as OpenSwoole would. */
    function endCoroutine(int $cid): void
    {
        asCoroutine($cid, static function () use ($cid): void {
            foreach (array_reverse(Coroutine::$defers[$cid] ?? []) as $hook) {
                $hook();
            }
        });

        unset(Coroutine::$defers[$cid], Coroutine::$contexts[$cid]);
    }

    /** Run $work in a coroutine of its own, start to finish. */
    function coroutine(callable $work): mixed
    {
        static $next = 0;
        $cid = ++$next;

        try {
            return asCoroutine($cid, $work);
        } finally {
            endCoroutine($cid);
        }
    }

    /**
     * How many leases the pool can hand out simultaneously right now.
     * This is the property that a slot leak destroys.
     *
     * One coroutine per lease, because a checkout is scoped to its coroutine:
     * asking twice in the same one returns the same EntityManager and would
     * measure nothing. The coroutines are left open until every lease has been
     * attempted, then ended so their slots go back.
     */
    function concurrentCapacity(TestDb $db): int
    {
        static $next = 1000;
        $cids = [];

        while (true) {
            $cid = ++$next;

            try {
                asCoroutine($cid, static fn() => $db->getConnection());
            } catch (PoolExhaustedException) {
                break;
            }

            $cids[] = $cid;
        }

        foreach ($cids as $cid) {
            endCoroutine($cid);
        }

        return count($cids);
    }

    function freshDb(int $size, callable $maker, float $timeout = 0.01): TestDb
    {
        TestDb::forget();
        TestDb::$maker = $maker;
        FakeEntityManager::$created = 0;

        return new TestDb('test_pool', 'domain', [
            'poolSize' => $size,
            'connectionDelay' => $timeout,
        ]);
    }

    echo "== 1. Pool is created empty (safe to build pre-fork) ==\n";
    $db = freshDb(4, fn() => new FakeEntityManager());
    check(
        'no EntityManagers built by the constructor',
        FakeEntityManager::$created === 0,
        'created=' . FakeEntityManager::$created
    );
    check('pool capacity honours poolSize from config', TestDb::pool('test_pool')->capacity() === 4);

    echo "\n== 2. poolSize is read from config, not hardcoded to 10 ==\n";
    $db = freshDb(3, fn() => new FakeEntityManager());
    check('capacity is 3, not 10', TestDb::pool('test_pool')->capacity() === 3);

    echo "\n== 3. Healthy lease round-trip ==\n";
    $db = freshDb(2, fn() => new FakeEntityManager());
    $em = coroutine(static function () use ($db) {
        $em = $db->getConnection();
        check('checkout returns a usable EntityManager', $em->isOpen());
        check(
            'asking again in the same coroutine reuses the checkout',
            $db->getConnection() === $em
        );
        $db->releaseConnection($em);
        check('identity map cleared on release', $em->clears === 1, 'clears=' . $em->clears);
        check(
            'the slot is still held until the coroutine ends',
            TestDb::pool('test_pool')->available() === 0
        );

        return $em;
    });
    check('slot returned to the pool', TestDb::pool('test_pool')->available() === 1);
    check('no in-flight leases', PoolStats::inUse('test_pool') === 0);
    check('can still serve 2 concurrent leases', concurrentCapacity($db) === 2);

    echo "\n== 4. THE REGRESSION: a closed EntityManager must not shrink the pool ==\n";
    // Previously the trait recursed on !isOpen() and dropped the closed EM, so
    // each occurrence permanently cost one slot until the pool was empty.
    // The pool is lazy, so the meaningful assertion is not how many idle
    // EntityManagers are sitting in the queue but how many concurrent leases
    // the pool can still satisfy.
    $db = freshDb(3, fn() => new FakeEntityManager(open: false));
    for ($i = 1; $i <= 20; $i++) {
        coroutine(static function () use ($db): void {
            $db->releaseConnection($db->getConnection());
        });
    }
    check('no leaked leases', PoolStats::inUse('test_pool') === 0);
    check(
        'dead EntityManagers were replaced, not dropped',
        PoolStats::snapshot()['test_pool']['replaced'] > 0
    );
    check(
        'pool can still serve all 3 concurrent leases after 20 closed-EM cycles',
        concurrentCapacity($db) === 3,
        'capacity=' . concurrentCapacity($db)
    );

    echo "\n== 4b. CONTROL: the old drop-the-dead-EM behaviour starves the pool ==\n";
    // Reproduces what the trait used to do, to confirm the test above is
    // actually detecting the bug rather than passing trivially.
    $db = freshDb(3, fn() => new FakeEntityManager(open: false));
    for ($i = 1; $i <= 3; $i++) {
        $leased = TestDb::pool('test_pool')->get(0.01);   // raw checkout
        // ...and never put it back, exactly as the recursive retry did.
        unset($leased);
    }
    check(
        'control pool is starved after 3 dropped leases',
        concurrentCapacity($db) === 0,
        'capacity=' . concurrentCapacity($db)
    );

    echo "\n== 5. Exhaustion throws a 503 instead of blocking forever ==\n";
    $db = freshDb(2, fn() => new FakeEntityManager());
    $holders = [5001, 5002];
    foreach ($holders as $cid) {
        asCoroutine($cid, static fn() => $db->getConnection());
    }
    $threw = null;
    try {
        coroutine(static fn() => $db->getConnection());
    } catch (PoolExhaustedException $e) {
        $threw = $e;
    }
    check('PoolExhaustedException thrown', $threw !== null);
    check('maps to HTTP 503', $threw?->getStatusCode() === 503);
    check('advertises Retry-After', $threw?->getRetryAfter() === 1);
    check('reports in-use count', $threw?->getInUse() === 2, 'inUse=' . $threw?->getInUse());
    check(
        'message carries no credentials or host',
        $threw !== null && !str_contains($threw->getMessage(), 'password')
    );
    foreach ($holders as $cid) {
        endCoroutine($cid);
    }
    check('slots come back when the holders end', concurrentCapacity($db) === 2);

    echo "\n== 6. Abandoned transaction is rolled back before reuse ==\n";
    $db = freshDb(1, fn() => new FakeEntityManager());
    $em = coroutine(static function () use ($db) {
        $em = $db->getConnection();
        $em->getConnection()->inTransaction = true;

        return $em;
    });
    check('transaction rolled back', $em->getConnection()->rollBacks === 1);
    check('transaction no longer active', !$em->getConnection()->isTransactionActive());
    check('rollback counted', PoolStats::snapshot()['test_pool']['rolledBack'] === 1);

    echo "\n== 7. A failing clear() replaces the slot rather than poisoning it ==\n";
    $db = freshDb(2, fn() => new FakeEntityManager(clearThrows: true));
    coroutine(static function () use ($db): void {
        $db->releaseConnection($db->getConnection());
    });
    check('failure recorded', PoolStats::snapshot()['test_pool']['clearFailed'] > 0);
    check('pool capacity preserved', concurrentCapacity($db) === 2);

    echo "\n== 8. A handler that throws still gives its slot back ==\n";
    $db = freshDb(1, fn() => new FakeEntityManager());
    try {
        coroutine(static function () use ($db): never {
            $db->withEntityManager(function (): never {
                throw new RuntimeException('handler blew up');
            });
        });
    } catch (RuntimeException) {
        // As the error-handling middleware would.
    }
    check('lease returned after an exception', PoolStats::inUse('test_pool') === 0);
    check('slot available again', TestDb::pool('test_pool')->available() === 1);
    check(
        'pool still serves requests',
        coroutine(static fn() => $db->getConnection()->isOpen())
    );

    echo "\n== 8b. Outside a coroutine the pool is bypassed, not misused ==\n";
    // Console commands and queue consumers have no coroutine. ClientPool leases
    // through a Channel, which OpenSwoole refuses outside one — reaching for it
    // killed the worker during onWorkerStop with "API must be called in the
    // coroutine". Without coroutines there is nothing to interleave, so one
    // EntityManager for the process is the same bound a pool of one would give.
    $db = freshDb(4, fn() => new FakeEntityManager());
    Coroutine::$cid = -1;
    $first = $db->getConnection();
    check('a checkout succeeds with no coroutine', $first->isOpen());
    check('the same one comes back on every call', $db->getConnection() === $first);
    check('no pool slot was taken', TestDb::pool('test_pool')->available() === 0);
    check('nothing counted as leased', PoolStats::inUse('test_pool') === 0);
    $db->releaseConnection($first);
    check('release still resets it', $first->clears === 1, 'clears=' . $first->clears);
    check('and it stays usable afterwards', $db->getConnection()->isOpen());

    echo "\n== 9. warmup() populates the pool (called from onWorkerStart) ==\n";
    $db = freshDb(5, fn() => new FakeEntityManager());
    check('empty before warmup', TestDb::pool('test_pool')->available() === 0);
    TestDb::warmup();
    check('full after warmup', TestDb::pool('test_pool')->available() === 5);

    echo "\n== 10. getEntityManager() works on a second instance (was uninitialised) ==\n";
    // connect() used to run only when the pool did not yet exist, leaving the
    // typed Closure property unset on every later instance.
    $second = new TestDb('test_pool', 'domain', ['poolSize' => 5]);
    $ok = false;
    try {
        $factory = $second->getEntityManager();
        $ok = $factory() instanceof EntityManagerInterface;
    } catch (Throwable $e) {
        $ok = false;
    }
    check('factory closure initialised on second instance', $ok);

    printf("\n%d passed, %d failed\n", $pass, $fail);
    exit($fail === 0 ? 0 : 1);
}
