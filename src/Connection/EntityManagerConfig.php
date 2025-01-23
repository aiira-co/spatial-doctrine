<?php

declare(strict_types=1);

namespace Spatial\Entity\Connection;

use OpenSwoole\Core\Coroutine\Client\ClientConfigInterface;

class EntityManagerConfig implements ClientConfigInterface
{
    public function __construct(
        public readonly \Closure $entityManager,
        public readonly string $domain = '',
        public readonly array $params = []
    ) {}
}