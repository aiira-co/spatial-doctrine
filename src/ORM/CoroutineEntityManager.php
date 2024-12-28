<?php
declare(strict_types=1);
namespace Spatial\Entity\ORM;

use Doctrine\ORM\EntityManager;

class CoroutineEntityManager extends EntityManager
{
    public static function createWithPool(ConnectionPool $pool, $config): self
    {
        $connection = $pool->getConnection();
        return new self($connection, $config);
    }
}
