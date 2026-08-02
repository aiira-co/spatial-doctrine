<?php

declare(strict_types=1);

namespace Spatial\Entity\Telemetry\Dbal;

/** @internal */
final class SqlAttributes
{
    private const MAX_STATEMENT_LENGTH = 1024;

    public static function operation(string $sql): string
    {
        if (preg_match('/^\s*([A-Za-z]+)/', $sql, $matches) === 1) {
            return strtoupper($matches[1]);
        }

        return 'QUERY';
    }

    public static function statement(string $sql): string
    {
        $normalized = preg_replace('/\s+/', ' ', trim($sql)) ?? $sql;

        if (strlen($normalized) <= self::MAX_STATEMENT_LENGTH) {
            return $normalized;
        }

        return substr($normalized, 0, self::MAX_STATEMENT_LENGTH) . '…';
    }
}
