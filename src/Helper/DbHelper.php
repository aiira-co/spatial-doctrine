<?php
declare(strict_types=1);
namespace Spatial\Entity\Helper;

use Doctrine\ORM\EntityManagerInterface;

class DbHelper
{
    private EntityManagerInterface $entityManager;

    public function __construct(EntityManagerInterface $entityManager)
    {
        $this->entityManager = $entityManager;

        // Register the custom DQL function
        $this->entityManager->getConfiguration()->addCustomStringFunction('callProcedure', CallProcedure::class);
    }

    public function Execute(string $procedureName, array $parameters = []): array
    {
        $dql = "SELECT callProcedure('$procedureName'";

        if (!empty($parameters)) {
            $dql .= ', ' . implode(', ', array_map(fn($param) => ":$param", array_keys($parameters)));
        }

        $dql .= ')';

        $query = $this->entityManager->createQuery($dql);

        foreach ($parameters as $key => $value) {
            $query->setParameter($key, $value);
        }

        return $query->getResult();
    }

    public function ExecuteAndGetAs(string $procedureName, array $parameters = [], string $className): array
    {
        $results = $this->Execute($procedureName, $parameters);

        $mappedResults = [];
        foreach ($results as $result) {
            $mappedResults[] = new $className($result);
        }

        return $mappedResults;
    }
}
