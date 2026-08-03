<?php

declare(strict_types=1);

/**
 * Doctrine cache factory wiring — no OpenSwoole or database required.
 *
 * Run: php tests/doctrine-cache-test.php
 */

if (! defined('AppConfig')) {
    define('AppConfig', ['enableProdMode' => false]);
}

if (! defined('DoctrineConfig')) {
    define('DoctrineConfig', [
        'doctrine' => [
            'orm' => [
                'metadata_cache_driver' => 'redis',
                'query_cache_driver' => 'redis',
                'result_cache_driver' => 'apcu',
            ],
        ],
    ]);
}

require dirname(__DIR__) . '/vendor/autoload.php';

use Spatial\Entity\Cache\DoctrineCacheFactory;
use Symfony\Component\Cache\Adapter\ArrayAdapter;

function assertTrue(bool $cond, string $msg): void
{
    if (! $cond) {
        fwrite(STDERR, "FAIL  {$msg}\n");
        exit(1);
    }
    echo "ok    {$msg}\n";
}

echo "== Doctrine cache factory ==\n";
assertTrue(
    DoctrineCacheFactory::create('metadata') instanceof ArrayAdapter,
    'dev mode ignores redis driver and uses ArrayAdapter for metadata',
);
assertTrue(
    DoctrineCacheFactory::create('query') instanceof ArrayAdapter,
    'dev mode uses ArrayAdapter for query cache',
);
assertTrue(
    DoctrineCacheFactory::create('result') instanceof ArrayAdapter,
    'unknown driver uses ArrayAdapter',
);

echo "\n3 passed.\n";
