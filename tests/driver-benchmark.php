<?php

declare(strict_types=1);

/**
 * Compares the coroutine PostgreSQL driver against pdo_pgsql on the same
 * query, so the choice between them rests on measurements rather than on the
 * assumption that non-blocking must be faster.
 *
 * The two differ in where concurrency comes from, so a single number is
 * misleading. pdo_pgsql blocks its worker, so one process runs one query at a
 * time and a service scales by adding workers. The coroutine driver lets one
 * process run poolSize queries at once. This measures both the per-query cost
 * of each driver and what the coroutine driver recovers once queries overlap.
 *
 *   docker exec -w /var/www <container> php vendor/spatial/doctrine/tests/driver-benchmark.php
 */

use OpenSwoole\Coroutine;
use Spatial\Entity\Driver\PgSQL\ConnectionPoolFactory;
use Spatial\Entity\Driver\PgSQL\Driver;

$autoload = getenv('SPATIAL_AUTOLOAD') ?: null;
foreach ([$autoload, __DIR__ . '/../../../autoload.php', __DIR__ . '/../vendor/autoload.php'] as $candidate) {
    if ($candidate !== null && is_file($candidate)) {
        require $candidate;
        break;
    }
}

if (!class_exists(Driver::class)) {
    fwrite(STDERR, "Could not autoload the driver. Set SPATIAL_AUTOLOAD.\n");
    exit(1);
}

$params = [
    'host'     => getenv('DB_HOST') ?: '127.0.0.1',
    'port'     => (int)(getenv('DB_PORT') ?: 5432),
    'dbname'   => getenv('DB_NAME') ?: 'postgres',
    'user'     => getenv('DB_USERNAME') ?: 'postgres',
    'password' => getenv('DB_PASSWORD') ?: '',
];

/** The shape of a real request: a few rows, a parameter, some columns. */
const SQL      = 'SELECT i, md5(i::text) AS h FROM generate_series(1, 20) i WHERE i > $1';
const PDO_SQL  = 'SELECT i, md5(i::text) AS h FROM generate_series(1, 20) i WHERE i > ?';
const ITERATIONS = 400;

function report(string $label, float $seconds, int $queries): void
{
    printf(
        "  %-46s %7.0f q/s   %6.2f ms/query\n",
        $label,
        $queries / $seconds,
        ($seconds / $queries) * 1000
    );
}

echo "\nDatabase: " . $params['dbname'] . ' at ' . $params['host'] . ':' . $params['port'] . "\n";
echo str_repeat('-', 78) . "\n";

// ---------------------------------------------------------------- pdo_pgsql

$pdo = new PDO(
    sprintf('pgsql:host=%s;port=%d;dbname=%s', $params['host'], $params['port'], $params['dbname']),
    $params['user'],
    $params['password'],
);

$start = microtime(true);
for ($i = 0; $i < ITERATIONS; $i++) {
    $stmt = $pdo->prepare(PDO_SQL);
    $stmt->execute([5]);
    $stmt->fetchAll(PDO::FETCH_ASSOC);
}
report('pdo_pgsql, sequential', microtime(true) - $start, ITERATIONS);

// A worker running pdo_pgsql cannot overlap queries at all, so its sequential
// rate is also its per-process ceiling. That is the number to beat.

// ------------------------------------------------------- coroutine driver

co::run(static function () use ($params): void {
    $poolSize = 6;
    $pool     = (new ConnectionPoolFactory())($params + [
        'poolSize'      => $poolSize,
        'connectionTtl' => 60,
        'usedTimes'     => (int)(getenv('BENCH_USED_TIMES') ?: 0),
    ]);

    // Fill first: connecting is expensive and would otherwise be charged to
    // whichever measurement happened to run first.
    $warm = [];
    for ($i = 0; $i < $poolSize; $i++) {
        $warm[] = $pool->get(2);
    }
    foreach ($warm as [$connection]) {
        $pool->put($connection);
    }

    $runQueries = static function (int $count) use ($pool): void {
        for ($i = 0; $i < $count; $i++) {
            [$connection] = $pool->get(2);
            $statement    = $connection->prepare(SQL);
            $statement->execute([5]);
            $statement->fetchAll();
            $pool->put($connection);
        }
    };

    $start = microtime(true);
    $runQueries(ITERATIONS);
    report('coroutine driver, sequential', microtime(true) - $start, ITERATIONS);

    foreach ([2, 6] as $concurrency) {
        $per   = intdiv(ITERATIONS, $concurrency);
        $start = microtime(true);
        $done  = new Coroutine\Channel($concurrency);
        for ($c = 0; $c < $concurrency; $c++) {
            Coroutine::create(static function () use ($runQueries, $per, $done): void {
                $runQueries($per);
                $done->push(true);
            });
        }
        for ($c = 0; $c < $concurrency; $c++) {
            $done->pop();
        }
        report("coroutine driver, $concurrency concurrent coroutines", microtime(true) - $start, $per * $concurrency);
    }
});

echo str_repeat('-', 78) . "\n";
echo "pdo_pgsql scales by adding workers; the coroutine driver scales within one.\n";
echo "Compare its concurrent rate against pdo_pgsql's sequential rate times the\n";
echo "workers you would otherwise have run.\n\n";
