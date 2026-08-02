<?php

declare(strict_types=1);

/**
 * Conformance test for the Spatial PostgreSQL coroutine driver.
 *
 * Needs a real PostgreSQL and the openswoole extension, so it runs as a script
 * rather than under PHPUnit. Point it at a database with DB_HOST, DB_PORT,
 * DB_NAME, DB_USERNAME and DB_PASSWORD, then run it inside a service container:
 *
 *     php vendor/spatial/doctrine/tests/driver-conformance.php
 *
 * It creates and drops one table, `spatial_driver_probe`.
 */

use Doctrine\DBAL\Configuration;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Exception\TableNotFoundException;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use OpenSwoole\Coroutine\Channel;
use OpenSwoole\Runtime;
use Spatial\Entity\Driver\PgSQL\ConnectionPoolFactory;
use Spatial\Entity\Driver\PgSQL\Driver as SpatialPgDriver;
use Spatial\Entity\Driver\PgSQL\DriverMiddleware;
use Spatial\Entity\Exception\PoolExhaustedException;

(static function (): void {
    $autoload = getenv('SPATIAL_AUTOLOAD') ?: null;

    foreach ([$autoload, ...array_map(
        static fn(string $p): string => __DIR__ . $p,
        ['/../../../autoload.php', '/../vendor/autoload.php', '/../../../../vendor/autoload.php']
    )] as $candidate) {
        if ($candidate !== null && is_file($candidate)) {
            require_once $candidate;

            // Lets the test run against a working copy of the package rather
            // than the installed one, without touching any vendor directory.
            $src = getenv('SPATIAL_DOCTRINE_SRC') ?: null;
            if ($src !== null) {
                spl_autoload_register(static function (string $class) use ($src): void {
                    $prefix = 'Spatial\\Entity\\';
                    if (! str_starts_with($class, $prefix)) {
                        return;
                    }
                    $path = rtrim($src, '/') . '/' . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
                    if (is_file($path)) {
                        require_once $path;
                    }
                }, true, true);
            }

            return;
        }
    }

    fwrite(STDERR, "Could not locate composer autoload. Set SPATIAL_AUTOLOAD.\n");
    exit(1);
})();

Runtime::enableCoroutine(true, Runtime::HOOK_ALL);

$pass = 0;
$fail = 0;

$check = static function (string $label, bool $ok, string $detail = '') use (&$pass, &$fail): void {
    $ok ? $pass++ : $fail++;
    printf("  [%s] %s%s\n", $ok ? ' ok ' : 'FAIL', $label, $detail === '' ? '' : " ($detail)");
};

$params = [
    'driverClass'       => SpatialPgDriver::class,
    'host'              => getenv('DB_HOST') ?: '127.0.0.1',
    'port'              => getenv('DB_PORT') ?: '5432',
    'dbname'            => getenv('DB_NAME') ?: 'postgres',
    'user'              => getenv('DB_USERNAME') ?: 'postgres',
    'password'          => getenv('DB_PASSWORD') ?: '',
    'poolId'            => 'conformance_pool',
    'poolSize'          => 4,
    'connectionDelay'   => 2,
    'connectionTtl'     => 60,
    'usedTimes'         => 100,
    'useConnectionPool' => true,
    'retry'             => ['maxAttempts' => 1, 'delay' => 100],
];

/** Build a DBAL connection backed by a fresh pool. */
$connect = static function (array $params): array {
    $pool   = (new ConnectionPoolFactory())($params);
    $config = new Configuration();
    $config->setMiddlewares([new DriverMiddleware($pool)]);

    return [DriverManager::getConnection($params, $config), $config];
};

co::run(static function () use ($params, $check, $connect) {
    [$conn] = $connect($params);

    echo "--- basic queries ---\n";
    $check('scalar select', $conn->fetchOne('SELECT 1') == 1);
    $check('associative row', $conn->fetchAssociative('SELECT 7 AS n')['n'] == 7);
    $check('multi-row fetchAll', count($conn->fetchAllAssociative('SELECT * FROM (VALUES (1),(2),(3)) t(n)')) === 3);
    $check('empty result set', $conn->fetchAllAssociative('SELECT 1 WHERE false') === []);
    $check('first column', $conn->fetchFirstColumn('SELECT * FROM (VALUES (1),(2)) t(n)') == [1, 2]);

    echo "\n--- parameterised queries ---\n";
    $check('single positional param', $conn->fetchOne('SELECT ?::int', [41]) == 41);
    $check('two params keep their order', $conn->fetchOne('SELECT ?::int + ?::int', [20, 22]) == 42);
    $check('null param', $conn->fetchOne('SELECT ?::text IS NULL', [null]) == true);
    $check('bool param', $conn->fetchOne('SELECT ?::bool', [true]) == true);
    $check('string param', $conn->fetchOne('SELECT ?::text', ['hello']) === 'hello');

    echo "\n--- writes, transactions and rollback ---\n";
    $conn->executeStatement('DROP TABLE IF EXISTS spatial_driver_probe');
    $conn->executeStatement('CREATE TABLE spatial_driver_probe (id int PRIMARY KEY, label text)');

    $check(
        'insert reports affected rows',
        $conn->executeStatement('INSERT INTO spatial_driver_probe VALUES (?,?), (?,?)', [1, 'a', 2, 'b']) === 2
    );

    $conn->beginTransaction();
    $conn->executeStatement('INSERT INTO spatial_driver_probe VALUES (?,?)', [3, 'c']);
    $conn->rollBack();
    $check('rollback discards the write', $conn->fetchOne('SELECT count(*) FROM spatial_driver_probe') == 2);

    $conn->beginTransaction();
    $conn->executeStatement('INSERT INTO spatial_driver_probe VALUES (?,?)', [4, 'd']);
    $conn->commit();
    $check('commit persists the write', $conn->fetchOne('SELECT count(*) FROM spatial_driver_probe') == 3);

    $check(
        'update reports affected rows',
        $conn->executeStatement('UPDATE spatial_driver_probe SET label = ? WHERE id = ?', ['z', 1]) === 1
    );
    $check(
        'delete reports affected rows',
        $conn->executeStatement('DELETE FROM spatial_driver_probe WHERE id = ?', [4]) === 1
    );

    echo "\n--- error classification (SQLSTATE to Doctrine exception) ---\n";
    try {
        $conn->executeQuery('SELECT * FROM a_table_that_is_not_there');
        $check('unknown table raises TableNotFoundException', false, 'nothing thrown');
    } catch (TableNotFoundException) {
        $check('unknown table raises TableNotFoundException', true);
    } catch (Throwable $e) {
        $check('unknown table raises TableNotFoundException', false, get_class($e));
    }

    try {
        $conn->executeStatement('INSERT INTO spatial_driver_probe VALUES (?,?)', [1, 'dup']);
        $check('duplicate key raises UniqueConstraintViolationException', false, 'nothing thrown');
    } catch (UniqueConstraintViolationException) {
        $check('duplicate key raises UniqueConstraintViolationException', true);
    } catch (Throwable $e) {
        $check('duplicate key raises UniqueConstraintViolationException', false, get_class($e));
    }

    $conn->executeStatement('DROP TABLE IF EXISTS spatial_driver_probe');
});

echo "\n--- the pool parallelises, and bounds itself ---\n";

/**
 * @param array<string, mixed> $params
 * @return array{ok:int, exhausted:int, other:array<string>, elapsed:float}
 */
$race = static function (array $params, int $concurrency, float $hold) use ($connect): array {
    $result = ['ok' => 0, 'exhausted' => 0, 'other' => [], 'elapsed' => 0.0];

    co::run(static function () use ($params, $concurrency, $hold, $connect, &$result) {
        [, $config] = $connect($params);

        $done    = new Channel($concurrency);
        $started = microtime(true);

        for ($i = 0; $i < $concurrency; $i++) {
            go(static function () use ($params, $config, $done, $hold) {
                try {
                    DriverManager::getConnection($params, $config)
                        ->executeQuery('SELECT pg_sleep(' . $hold . ')');
                    $done->push(['ok', '']);
                } catch (PoolExhaustedException) {
                    $done->push(['exhausted', '']);
                } catch (Throwable $e) {
                    $done->push(['other', get_class($e) . ': ' . $e->getMessage()]);
                }
            });
        }

        for ($i = 0; $i < $concurrency; $i++) {
            [$kind, $note] = $done->pop();
            if ($kind === 'other') {
                $result['other'][] = $note;
            } else {
                $result[$kind]++;
            }
        }
        $result['elapsed'] = microtime(true) - $started;
    });

    return $result;
};

$r = $race(['poolSize' => 4, 'connectionDelay' => 5, 'poolId' => 'race_wide'] + $params, 4, 1.0);
$check('4 concurrent 1s queries on a pool of 4 overlap', $r['elapsed'] < 2.0, sprintf('%.2fs', $r['elapsed']));
$check('all four completed', $r['ok'] === 4, sprintf('%d ok', $r['ok']));

$r = $race(['poolSize' => 1, 'connectionDelay' => 5, 'poolId' => 'race_narrow'] + $params, 3, 0.5);
$check('a pool of 1 serialises rather than failing', $r['ok'] === 3, sprintf('%d ok', $r['ok']));
$check('and takes about as long as the work', $r['elapsed'] >= 1.4, sprintf('%.2fs', $r['elapsed']));

$r = $race(['poolSize' => 1, 'connectionDelay' => 1, 'poolId' => 'race_shed'] + $params, 3, 2.0);
$check('over-subscription sheds load instead of queueing forever', $r['exhausted'] === 2, sprintf('%d shed', $r['exhausted']));
$check('the holder still succeeds', $r['ok'] === 1, sprintf('%d ok', $r['ok']));
$check('nothing fails for another reason', $r['other'] === [], implode('; ', array_unique($r['other'])));

echo "\n--- PoolExhaustedException carries HTTP intent ---\n";

co::run(static function () use ($params, $check, $connect) {
    $tiny = ['poolSize' => 1, 'connectionDelay' => 1, 'poolId' => 'http_shape'] + $params;
    [, $config] = $connect($tiny);

    $done = new Channel(2);
    for ($i = 0; $i < 2; $i++) {
        go(static function () use ($tiny, $config, $done) {
            try {
                DriverManager::getConnection($tiny, $config)->executeQuery('SELECT pg_sleep(2)');
                $done->push(null);
            } catch (PoolExhaustedException $e) {
                $done->push($e);
            } catch (Throwable) {
                $done->push(null);
            }
        });
    }

    $caught = null;
    for ($i = 0; $i < 2; $i++) {
        $caught = $done->pop() ?? $caught;
    }

    $check('exhaustion surfaces as PoolExhaustedException', $caught instanceof PoolExhaustedException);
    $check('with status 503', $caught?->getStatusCode() === 503, 'got ' . var_export($caught?->getStatusCode(), true));
    $check('with a Retry-After', $caught?->getRetryAfter() !== null);
});

printf("\n%d passed, %d failed\n", $pass, $fail);
exit($fail === 0 ? 0 : 1);
