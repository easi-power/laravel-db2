<?php

namespace Easi\DB2\Database\Connectors;

/**
 * Class ODBCZOSConnector
 *
 * @package Easi\DB2\Database\Connectors
 */
class ODBCZOSConnector extends ODBCConnector
{
    /**
     * @param array $config
     *
     * @return string
     */
    protected function getDsn(array $config): string
    {
        $dsnParts = [
            'odbc:DRIVER=%s',
            'Database=%s',
            'Hostname=%s',
            'Port=%s',
            'Protocol=TCPIP',
            '', // Just to add a semicolon to the end of string
        ];

        $dsnConfig = [
            $config['driverName'],
            $config['database'],
            $config['host'],
            $config['port'],
        ];

        return sprintf(implode(';', $dsnParts), ...$dsnConfig);
    }
}
