<?php

declare(strict_types=1);

namespace Spatial\Entity\Driver\PgSQL;

use Closure;
use OpenSwoole\Coroutine\Channel;
use OpenSwoole\Coroutine\PostgreSQL;
use Spatial\Entity\Driver\PgSQL\Exception\DriverException;
use Throwable;
use WeakMap;

use function gc_collect_cycles;
use function time;

final class ConnectionPool implements ConnectionPoolInterface
{
    private Channel $chan;
    /** @psalm-var WeakMap<PostgreSQL, ConnectionStats> $map */
    private WeakMap $map;
    /** Connections being opened right now, counted against `size`. */
    private int $pending = 0;

    public function __construct(
        private Closure $constructor,
        private int $size,
        private ?int $connectionTtl = null,
        private ?int $connectionUseLimit = null
    ) {
        if ($this->size < 0) {
            throw new DriverException('Expected, connection pull size > 0');
        }
        $this->chan = new Channel($this->size);
        /** @psalm-suppress PropertyTypeCoercion */
        $this->map = new WeakMap();
    }

    /** @psalm-return array{PostgreSQL|null, ConnectionStats|null } */
    public function get(float $timeout = -1) : array
    {
        if ($this->chan->isEmpty()) {
                /** try to fill pull with new connect */
                $this->make();
        }
        /** @var PostgreSQL|null $connection */
        $connection = $this->chan->pop($timeout);
        if (! $connection instanceof PostgreSQL) {
            return [null, null];
        }

        return [
            $connection,
            $this->map[$connection] ?? throw new DriverException('Connection stats could not be empty'),
        ];
    }

    public function put(PostgreSQL $connection) : void
    {
        $stats = $this->map[$connection] ?? null;
        if (! $stats || $stats->isOverdue()) {
            $this->remove($connection);

            return;
        }
        if ($this->size <= $this->chan->length()) {
            $this->remove($connection);

            return;
        }

        /** to prevent hypothetical freeze if channel is full, will never happen but for sure */
        if (! $this->chan->push($connection, 1)) {
            $this->remove($connection);
        }
    }

    public function close() : void
    {
        $this->chan->close();
        gc_collect_cycles();
    }

    public function capacity() : int
    {
        return $this->map->count();
    }

    public function length() : int
    {
        return $this->chan->length();
    }

    public function stats() : array
    {
        return $this->chan->stats();
    }

    /**
     * Exclude object data from doctrine cache serialization
     *
     * @see vendor/doctrine/dbal/src/Cache/QueryCacheProfile.php:127
     */
    public function __serialize() : array
    {
        return [];
    }

    /**
     * @param string $data
     */
    public function __unserialize($data) : void
    {
        // Do nothing
    }

    private function remove(PostgreSQL $connection) : void
    {
        $this->map->offsetUnset($connection);
        unset($connection);
    }

    /**
     * Open one more connection, if the pool is allowed to grow.
     *
     * The slot is reserved before connecting. Connecting yields, so without
     * the reservation two coroutines could both see spare capacity and both
     * open a connection, putting the pool over `size` — which is the one
     * number the whole connection budget is built on.
     */
    private function make() : void
    {
        if ($this->size <= $this->capacity() + $this->pending) {
            return;
        }

        $this->pending++;

        try {
            /** @var PostgreSQL $connection */
            $connection = ($this->constructor)();

            // Register the stats before publishing the connection. push() hands
            // it straight to any coroutine already blocked in pop(), which then
            // reads this map immediately — so writing the entry afterwards let
            // the consumer win the race and fail with "stats could not be
            // empty". Assigning first is safe because a WeakMap write cannot
            // yield, whereas push() can.
            $this->map[$connection] = new ConnectionStats(
                time(),
                1,
                $this->connectionTtl,
                $this->connectionUseLimit
            );

            /** @psalm-suppress PossiblyNullReference */
            if (! $this->chan->push($connection, 1)) {
                $this->map->offsetUnset($connection);
            }
        } finally {
            $this->pending--;
        }
    }
}
