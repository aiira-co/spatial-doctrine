<?php
declare(strict_types=1);

namespace Spatial\Entity\Helper;

use Doctrine\DBAL\Result;
use Doctrine\ORM\EntityManagerInterface;
use Spatial\Common\Helper\Caster;

/**
 * Helper class for database interactions with stored procedures.
 */
class DbHelper
{
    /**
     * @var EntityManagerInterface Doctrine's EntityManager for database operations.
     */
    private EntityManagerInterface $entityManager;

    /**
     * @var Result Stores the query result after execution.
     */
    private Result $_queryResults;

    /**
     * DbHelper constructor.
     *
     * @param EntityManagerInterface $entityManager Entity manager for database operations.
     */
    public function __construct(EntityManagerInterface $entityManager)
    {
        $this->entityManager = $entityManager;
    }

    /**
     * Executes a stored procedure with optional parameters.
     *
     * @param string $procedureName Name of the stored procedure to execute.
     * @param array $parameters Associative array of parameters to bind.
     * @param string $columns Optional columns to fetch (default is '*').
     *
     * @return $this Returns the instance for chaining.
     * @throws \RuntimeException If the query execution fails.
     */
    public function execute(string $procedureName, array $parameters = [], string $columns = '*'): self
    {
        $sql = "SELECT $columns FROM $procedureName(";

        if (!empty($parameters)) {
            $sql .= implode(', ', array_map(fn($param) => ":$param", array_keys($parameters)));
        }

        $sql .= ')';

        $connection = $this->entityManager->getConnection();
        $stmt = $connection->prepare($sql);

        foreach ($parameters as $key => $value) {
            $stmt->bindValue($key, $value);
        }

        try {
            $this->_queryResults = $stmt->executeQuery();
        } catch (\Exception $e) {
            throw new \RuntimeException('Database query failed: ' . $e->getMessage(), 0, $e);
        }

        return $this;
    }

    /**
     * Gets the raw query result object.
     *
     * @return Result The result object from the executed query.
     */
    public function get(): Result
    {
        return $this->_queryResults;
    }

    /**
     * Fetches the first column from the query result.
     *
     * @return mixed The first column value or null if no result exists.
     */
    public function getFirstColumn(): mixed
    {
        $columns = $this->_queryResults->fetchFirstColumn();
        return $columns[0] ?? null;
    }

    /**
     * Fetches all rows from the query result as an associative array.
     *
     * @return mixed The associative array of all rows in the result set.
     */
    public function getAll(): mixed
    {
        return $this->_queryResults->fetchAllAssociative();
    }

    /**
     * Fetches the first column of the query result and casts it to a specified class.
     *
     * @param string $className Fully qualified name of the class to cast the result into.
     *
     * @return object An object of the specified class.
     */
    public function getAs(string $className): ?object
    {
        $results = $this->getFirstColumn();
        if($results == null)
        {
            return null;
        }
        return Caster::castToObject($className, $results);
    }

    /**
     * Executes a stored procedure and casts the result into a specified class.
     *
     * @param string $procedureName Name of the stored procedure to execute.
     * @param array $parameters Associative array of parameters to bind.
     * @param string $className Fully qualified name of the class to cast the result into.
     *
     * @return mixed An array of objects of the specified class or null if no results exist.
     */
    public function executeAndGetAs(string $procedureName, array $parameters = [], string $className = ''): mixed
    {
        $this->execute($procedureName, $parameters);
        $results = $this->getAll();

        if (empty($results)) {
            return null;
        }

        return array_map(fn($result) => Caster::castToObject($className, $result), $results);
    }
}
