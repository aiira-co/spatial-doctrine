<?php

declare(strict_types=1);

namespace Spatial\Entity\Driver\PgSQL\Exception;

use ArrayAccess;
use OpenSwoole\Coroutine\PostgreSQL;

/** @psalm-immutable */
trait ExceptionFromConnectionTrait
{
    public static function fromConnection(PostgreSQL $connection) : self
    {
        /** @var ArrayAccess $resultDiag */
        $resultDiag = $connection->resultDiag ?? [];
        $sqlstate   = (string) ($resultDiag['sqlstate'] ?? '');

        return new self(
            (string) $connection->error,
            (string) $connection->errCode,
            $sqlstate,
            (int) $connection->errCode,
        );
    }
}
