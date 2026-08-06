<?php

namespace Easi\DB2\Support;

use Illuminate\Database\Connection;
use TypeError;

class ConnectionGuard
{
    /**
     * Assert that a resolved connection is a concrete Illuminate connection.
     *
     * @param mixed $connection
     * @return Connection
     */
    public static function assertConcrete(mixed $connection): Connection
    {
        if (! $connection instanceof Connection) {
            throw new TypeError('Expected a concrete Illuminate\Database\Connection instance.');
        }

        return $connection;
    }
}
