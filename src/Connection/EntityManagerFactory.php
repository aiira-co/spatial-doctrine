<?php
declare(strict_types=1);
namespace Spatial\Entity\Connection;

use Doctrine\ORM\EntityManagerInterface;
use OpenSwoole\Core\Coroutine\Client\ClientFactoryInterface;
use OpenSwoole\Core\Coroutine\Client\ClientConfigInterface;

use Spatial\Entity\DoctrineEntity;

class EntityManagerFactory implements ClientFactoryInterface
{
    public static function make(ClientConfigInterface $config): EntityManagerInterface
    {
        if($config->entityManager){
            return ($config->entityManager)();
        }

        $doctrine = new DoctrineEntity($config->domain);
        return $doctrine->entityManager($config->params);

       
    }
}