<?php

declare(strict_types=1);

namespace Spatial\Entity\Driver\PgSQL;

use Doctrine\DBAL\Driver\Result as ResultInterface;
use OpenSwoole\Coroutine\PostgreSQLStatement;

use const OPENSWOOLE_PGSQL_NUM;

/**
 * A executed statement's rows, presented to Doctrine as a forward-only cursor.
 *
 * OpenSwoole addresses rows by index rather than advancing a cursor, and
 * reading past the last row raises a PHP warning rather than returning false
 * quietly. Both are hidden here: the row count is captured up front and every
 * read is bounded by it, so Doctrine gets the sequential `false`-terminated
 * interface it expects without a warning per exhausted result set.
 */
final class Result implements ResultInterface
{
    private int $cursor = 0;

    private int $numRows;

    private int $affectedRows;

    private int $fieldCount;

    public function __construct(private ?PostgreSQLStatement $statement)
    {
        // Read eagerly: re-executing the statement replaces these, and Doctrine
        // may hold the Result past that point.
        $this->numRows      = $statement === null ? 0 : (int) $statement->numRows();
        $this->affectedRows = $statement === null ? 0 : (int) $statement->affectedRows();
        $this->fieldCount   = $statement === null ? 0 : (int) $statement->fieldCount();
    }

    /** {@inheritdoc} */
    public function fetchNumeric() : array|false
    {
        if ($this->statement === null || $this->cursor >= $this->numRows) {
            return false;
        }

        /** @psalm-var list<mixed>|false $row */
        $row = $this->statement->fetchArray($this->cursor, OPENSWOOLE_PGSQL_NUM);
        $this->cursor++;

        return $row;
    }

    /** {@inheritdoc} */
    public function fetchAssociative() : array|false
    {
        if ($this->statement === null || $this->cursor >= $this->numRows) {
            return false;
        }

        /** @psalm-var array<string,mixed>|false $row */
        $row = $this->statement->fetchAssoc($this->cursor);
        $this->cursor++;

        return $row;
    }

    /** {@inheritdoc} */
    public function fetchOne() : mixed
    {
        $row = $this->fetchNumeric();

        return $row === false ? false : $row[0];
    }

    /** {@inheritdoc} */
    public function fetchAllNumeric() : array
    {
        $rows = [];
        while (($row = $this->fetchNumeric()) !== false) {
            $rows[] = $row;
        }

        return $rows;
    }

    /** {@inheritdoc} */
    public function fetchAllAssociative() : array
    {
        $rows = [];
        while (($row = $this->fetchAssociative()) !== false) {
            $rows[] = $row;
        }

        return $rows;
    }

    /** {@inheritdoc} */
    public function fetchFirstColumn() : array
    {
        $values = [];
        while (($row = $this->fetchNumeric()) !== false) {
            $values[] = $row[0];
        }

        return $values;
    }

    /**
     * {@inheritdoc}
     *
     * Postgres reports affected rows for INSERT, UPDATE and DELETE, and the
     * selected row count for SELECT, which is what Doctrine wants from both.
     */
    public function rowCount() : int
    {
        return $this->affectedRows;
    }

    /** {@inheritdoc} */
    public function columnCount() : int
    {
        return $this->fieldCount;
    }

    /** {@inheritdoc} */
    public function free() : void
    {
        $this->statement = null;
        $this->cursor    = $this->numRows;
    }
}
