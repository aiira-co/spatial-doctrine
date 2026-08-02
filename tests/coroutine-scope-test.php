<?php

declare(strict_types=1);

/**
 * Tests for coroutine-scoped checkouts in DbConnection.
 *
 * Needs the openswoole extension for real coroutines and `defer`, so run it
 * inside a service container:
 *
 *     php vendor/spatial/doctrine/tests/coroutine-scope-test.php
 *
 * No database is required — Doctrine and the pool are stubbed, exactly as in
 * pool-lease-test.php. What is under test is that a checkout belongs to the
 * coroutine that made it and comes back when that coroutine ends, however it
 * ends. That property is what makes the 651 handlers with no try/finally safe.
 */

/* ---------------------------------------------------------------- stubs */

namespace OpenSwoole\Core\Coroutine\Client {
    interface ClientConfigInterface
    {
    }

    interface ClientFactoryInterface
    {
    }
}

namespace OpenSwoole\Core\Coroutine\Pool {

    /** Mirrors the vendored ClientPool contract: lazy make, false on empty. */
    class ClientPool
    {
        public const DEFAULT_SIZE = 16;

        /** @var array<int, mixed> */
        private array $queue = [];
        private int $size;
        private int $num = 0;
        private $factory;
        private $config;

        public function __construct($factory, $config, int $size = self::DEFAULT_SIZE, bool $heartbeat = false)
        {
            $this->factory = $factory;
            $this->config  = $config;
            $this->size    = $size;
        }

        public function fill(): void
        {
            while ($this->size > $this->num) {
                $this->make();
            }
        }

        public function get(float $timeout = -1)
        {
            if ($this->queue === [] && $this->num < $this->size) {
                $this->make();
            }

            if ($this->queue === []) {
                return false;
            }

            return array_shift($this->queue);
        }

        public function put($item): void
        {
            if ($item === null) {
                $this->num--;

                return;
            }

            $this->queue[] = $item;
        }

        public function available(): int
        {
            return count($this->queue);
        }

        /**
         * Slots currently checked out. The pool builds EntityManagers lazily,
         * so the free count alone says nothing — this is what a leak moves.
         */
        public function inUse(): int
        {
            return $this->num - count($this->queue);
        }

        private function make(): void
        {
            $this->num++;
            $this->queue[] = ($this->factory)::make($this->config);
        }
    }
}

namespace Doctrine\DBAL {
    class Connection
    {
        public bool $closed = false;
        public bool $inTransaction = false;

        public function isTransactionActive(): bool
        {
            return $this->inTransaction;
        }

        public function rollBack(): void
        {
            $this->inTransaction = false;
        }

        public function close(): void
        {
            $this->closed = true;
        }

        public function executeQuery(string $sql)
        {
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

    require __DIR__ . '/../../spatial-core/src/core/Exception/HttpAwareExceptionInterface.php';
    require __DIR__ . '/../src/Connection/EntityManagerConfig.php';
    require __DIR__ . '/../src/Connection/EntityManagerFactory.php';
    require __DIR__ . '/../src/Pool/PoolConfig.php';
    require __DIR__ . '/../src/Pool/PoolStats.php';
    require __DIR__ . '/../src/Exception/PoolExhaustedException.php';
    require __DIR__ . '/../src/DbConnection.php';

    use Doctrine\ORM\EntityManagerInterface;
    use OpenSwoole\Coroutine\Channel;
    use Spatial\Entity\DbConnection;
    use Spatial\Entity\Exception\PoolExhaustedException;
    use Spatial\Entity\Test\FakeEntityManager;

    final class TestDb extends DbConnection
    {
        protected function connect(string $domain, array $params): void
        {
            $this->entityManager = static fn(): EntityManagerInterface => new FakeEntityManager();
        }

        public static function pool(string $poolId): object
        {
            return self::$connectionPool[$poolId];
        }

        public static function forget(): void
        {
            self::$connectionPool = [];
        }
    }

    $pass = 0;
    $fail = 0;

    $check = static function (string $label, bool $ok, string $detail = '') use (&$pass, &$fail): void {
        $ok ? $pass++ : $fail++;
        printf("  [%s] %s%s\n", $ok ? ' ok ' : 'FAIL', $label, $detail === '' ? '' : " ({$detail})");
    };

    /** @return array{TestDb, object} */
    $build = static function (string $poolId, int $size, float $timeout = 0.2): array {
        TestDb::forget();
        $db = new TestDb($poolId, 'test', [
            'poolSize'        => $size,
            'connectionDelay' => $timeout,
        ]);

        return [$db, TestDb::pool($poolId)];
    };

    echo "== 1. Repeat calls in one coroutine share a single checkout ==\n";

    co::run(static function () use ($build, $check) {
        [$db, $pool] = $build('scope_same', 4);

        go(static function () use ($db, $pool, $check) {
            $a = $db->getConnection();
            $b = $db->getConnection();
            $c = $db->getConnection();

            $check('three calls return the same EntityManager', $a === $b && $b === $c);
            $check('and consume one slot, not three', $pool->inUse() === 1, $pool->inUse() . ' in use');
        });
    });

    echo "\n== 2. The slot comes back when the coroutine ends, with no release call ==\n";

    co::run(static function () use ($build, $check) {
        [$db, $pool] = $build('scope_auto', 2);

        go(static function () use ($db) {
            // Deliberately no releaseConnection: this is the shape of 651 of
            // the 652 handlers across the services.
            $db->getConnection();
        });

        // defer runs as that coroutine finishes, before this one resumes.
        $check('slot returned automatically', $pool->inUse() === 0, $pool->inUse() . ' still in use');
    });

    echo "\n== 3. And it comes back when the handler throws ==\n";

    co::run(static function () use ($build, $check) {
        [$db, $pool] = $build('scope_throw', 2);

        go(static function () use ($db) {
            // The handler throws and something upstream catches it, which is
            // what the framework's error middleware does per request.
            try {
                (static function () use ($db): void {
                    $db->getConnection();

                    throw new RuntimeException('handler blew up');
                })();
            } catch (RuntimeException) {
            }
        });

        $check('slot returned after an exception', $pool->inUse() === 0, $pool->inUse() . ' still in use');
    });

    echo "\n== 4. And after an early return, the case that used to leak ==\n";

    co::run(static function () use ($build, $check) {
        [$db, $pool] = $build('scope_early', 1);

        for ($i = 0; $i < 5; $i++) {
            go(static function () use ($db) {
                $db->getConnection();

                return; // early return, no release
            });
        }

        $check('a pool of 1 survives 5 early-returning handlers', $pool->inUse() === 0, $pool->inUse() . ' still in use');
    });

    echo "\n== 5. Concurrent coroutines get their own EntityManagers ==\n";

    co::run(static function () use ($build, $check) {
        [$db] = $build('scope_distinct', 4);

        $seen = new Channel(3);
        $held = new Channel(3);

        for ($i = 0; $i < 3; $i++) {
            go(static function () use ($db, $seen, $held) {
                $em = $db->getConnection();
                $seen->push(spl_object_id($em));
                // Hold the checkout until every coroutine has one.
                $held->pop();
            });
        }

        $ids = [$seen->pop(), $seen->pop(), $seen->pop()];
        $check('three concurrent coroutines hold three distinct EntityManagers', count(array_unique($ids)) === 3);

        for ($i = 0; $i < 3; $i++) {
            $held->push(true);
        }
    });

    echo "\n== 6. Concurrency stays bounded by poolSize ==\n";

    co::run(static function () use ($build, $check) {
        [$db] = $build('scope_bounded', 2, 0.05);

        $outcome = new Channel(4);
        $held    = new Channel(4);

        for ($i = 0; $i < 4; $i++) {
            go(static function () use ($db, $outcome, $held) {
                try {
                    $db->getConnection();
                    $outcome->push('ok');
                    $held->pop();
                } catch (PoolExhaustedException) {
                    $outcome->push('exhausted');
                }
            });
        }

        $tally = ['ok' => 0, 'exhausted' => 0];
        for ($i = 0; $i < 4; $i++) {
            $tally[$outcome->pop()]++;
        }

        $check('two of four coroutines get a slot', $tally['ok'] === 2, json_encode($tally));
        $check('the other two are shed as 503, not queued forever', $tally['exhausted'] === 2);

        for ($i = 0; $i < 2; $i++) {
            $held->push(true);
        }
    });

    echo "\n== 7. releaseConnection() still resets, but no longer double-returns ==\n";

    co::run(static function () use ($build, $check) {
        [$db, $pool] = $build('scope_release', 2);

        go(static function () use ($db, $pool, $check) {
            $em = $db->getConnection();
            $db->releaseConnection($em);

            $check('identity map cleared', $em->clears === 1, $em->clears . ' clears');
            $check('slot still held for the rest of the coroutine', $pool->inUse() === 1, $pool->inUse() . ' in use');
            $check('and the same EntityManager is handed back', $db->getConnection() === $em);
        });

        $check('slot returned exactly once at coroutine end', $pool->inUse() === 0, $pool->inUse() . ' in use');
    });

    echo "\n== 8. A closed EntityManager is replaced without taking a second slot ==\n";

    co::run(static function () use ($build, $check) {
        [$db, $pool] = $build('scope_dead', 2);

        go(static function () use ($db, $pool, $check) {
            $first = $db->getConnection();
            $first->open = false;

            $second = $db->getConnection();

            $check('a fresh EntityManager is issued', $second !== $first && $second->isOpen());
            $check('still only one slot in use', $pool->inUse() === 1, $pool->inUse() . ' in use');
        });

        $check('and it is returned at coroutine end', $pool->inUse() === 0, $pool->inUse() . ' in use');
    });

    printf("\n%d passed, %d failed\n", $pass, $fail);
    exit($fail === 0 ? 0 : 1);
}
