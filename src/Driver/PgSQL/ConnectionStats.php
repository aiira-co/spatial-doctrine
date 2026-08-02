<?php

declare(strict_types=1);

namespace Spatial\Entity\Driver\PgSQL;

use function time;

class ConnectionStats
{
    public function __construct(
        public int $lastInteraction,
        public int $counter,
        private ?int $ttl = null,
        private ?int $counterLimit = null,
    ) {
    }

    /**
     * Whether this connection should be closed rather than pooled again.
     *
     * Zero means "no limit" for both bounds, which is what the factory's
     * DEFAULT_USAGE_LIMIT of 0 intends. Testing the limit for null only, a
     * connection whose limit was 0 was overdue on its first use, because its
     * counter starts at 1: the pool then closed every connection on return and
     * opened a new one for the next query. Connecting costs about 10ms against
     * 0.5ms for the query, so the default configuration ran roughly twenty
     * times slower than it should while looking like a working pool.
     */
    public function isOverdue() : bool
    {
        $counterOverflow = $this->counterLimit !== null
            && $this->counterLimit > 0
            && $this->counter > $this->counterLimit;

        $ttlOverdue = $this->ttl !== null
            && $this->ttl > 0
            && time() - $this->lastInteraction > $this->ttl;

        return $counterOverflow || $ttlOverdue;
    }
}
