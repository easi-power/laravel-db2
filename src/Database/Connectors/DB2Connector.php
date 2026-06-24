<?php

namespace Easi\DB2\Database\Connectors;

use Exception;
use Illuminate\Database\Connectors\Connector;
use Illuminate\Database\Connectors\ConnectorInterface;
use PDO;

/**
 * Class IBMConnector
 *
 * @package Easi\DB2\Database\Connectors
 */
class DB2Connector extends Connector implements ConnectorInterface
{
    /**
     * @param array $config
     *
     * @return PDO
     * @throws Exception
     */
    public function connect(array $config): PDO
    {
        $dsn = $this->getDsn($config);
        $options = $this->getOptions($config);
        $connection = $this->createConnection($dsn, $config, $options);

        if (isset($config['schema'])) {
            $schema = $config['schema'];

            $connection->prepare('set schema ' . $schema)
                       ->execute();
        }

        return $connection;
    }
}
