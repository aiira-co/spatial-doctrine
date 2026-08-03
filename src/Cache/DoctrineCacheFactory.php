<?php

declare(strict_types=1);

namespace Spatial\Entity\Cache;

use Psr\Cache\CacheItemPoolInterface;
use Redis;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\Cache\Adapter\RedisAdapter;

/**
 * Builds Doctrine ORM cache pools from doctrine.yaml driver settings.
 *
 * metadata_cache_driver, query_cache_driver and result_cache_driver are
 * declared in every service's doctrine.yaml but were never passed to
 * Configuration. Each purpose gets its own Redis namespace so keys cannot
 * collide.
 */
final class DoctrineCacheFactory
{
    private static ?Redis $redis = null;

    /**
     * @param 'metadata'|'query'|'result' $purpose
     */
    public static function create(string $purpose): CacheItemPoolInterface
    {
        $driver = self::driverFor($purpose);

        if (self::useArrayAdapter($driver)) {
            return new ArrayAdapter();
        }

        if (! class_exists(RedisAdapter::class)) {
            error_log(
                'Doctrine ' . $purpose . ' cache requested driver "' . $driver
                . '" but symfony/cache is not installed; using in-memory cache.'
            );

            return new ArrayAdapter();
        }

        try {
            return new RedisAdapter(self::redis(), self::namespace($purpose));
        } catch (\Throwable $e) {
            error_log(
                'Doctrine ' . $purpose . ' cache could not reach Redis ('
                . $e->getMessage() . '); using in-memory cache.'
            );

            return new ArrayAdapter();
        }
    }

    private static function driverFor(string $purpose): ?string
    {
        $orm = DoctrineConfig['doctrine']['orm'] ?? [];

        return $orm[$purpose . '_cache_driver'] ?? null;
    }

    private static function useArrayAdapter(?string $driver): bool
    {
        if ($driver === null || $driver === 'array') {
            return true;
        }

        $isDev = !(AppConfig['enableProdMode'] ?? true);

        return $isDev || $driver !== 'redis';
    }

    private static function namespace(string $purpose): string
    {
        $app = $_ENV['APP_NAME'] ?? ($_SERVER['APP_NAME'] ?? 'spatial');

        return 'doctrine_' . $purpose . '_' . md5($app);
    }

    private static function redis(): Redis
    {
        if (self::$redis instanceof Redis) {
            return self::$redis;
        }

        if (! extension_loaded('redis')) {
            throw new \RuntimeException('ext-redis is not loaded');
        }

        $host = $_ENV['REDIS_HOST'] ?? ($_SERVER['REDIS_HOST'] ?? '127.0.0.1');
        $port = (int)($_ENV['REDIS_PORT'] ?? ($_SERVER['REDIS_PORT'] ?? 6379));
        $password = $_ENV['REDIS_PASSWORD'] ?? ($_SERVER['REDIS_PASSWORD'] ?? null);
        $user = $_ENV['REDIS_USER'] ?? ($_SERVER['REDIS_USER'] ?? null);
        $db = (int)($_ENV['REDIS_DB'] ?? ($_SERVER['REDIS_DB'] ?? 0));

        $redis = new Redis();
        if (! $redis->connect($host, $port, 2.0)) {
            throw new \RuntimeException(sprintf('Could not connect to Redis at %s:%d', $host, $port));
        }

        if ($user !== null && $user !== '' && $password !== null && $password !== '') {
            if (! $redis->auth([$user, $password])) {
                throw new \RuntimeException('Redis ACL authentication failed');
            }
        } elseif ($password !== null && $password !== '') {
            if (! $redis->auth($password)) {
                throw new \RuntimeException('Redis authentication failed');
            }
        }

        if ($db !== 0) {
            $redis->select($db);
        }

        self::$redis = $redis;

        return self::$redis;
    }

    /** Visible to tests that need to reset the shared connection. */
    public static function resetForTests(): void
    {
        self::$redis = null;
    }
}
