<?php

namespace Easi\DB2\Queue;

use Easi\DB2\Support\ConnectionGuard;
use Illuminate\Queue\Connectors\DatabaseConnector;

class DB2Connector extends DatabaseConnector
{
    /**
     * Establish a queue connection.
     *
     * @param array $config
     * @return DB2Queue
     */
    public function connect(array $config): DB2Queue
    {
        $connection = ConnectionGuard::assertConcrete(
            $this->connections->connection($config['connection'] ?? null)
        );

        return new DB2Queue(
            $connection,
            $config['table'],
            $config['queue'],
            $config['retry_after'] ?? 60
        );
    }
}
