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
    protected function getDsn(array $config)
    {
        $dsnParts = [
            'odbc:DRIVER=%s',
            'DATABASE=%s',
            'HOSTNAME=%s',
            'PORT=%s',
            'PROTOCOL=TCPIP',
            'UID=%s',
            'PWD=%s',
            '', // Just to add a semicolon to the end of string
        ];

        $dsnConfig = [
            $config['driverName'],
            $config['database'],
            $config['host'],
            $config['port'],
            $config['username'],
            $config['password'],
        ];

        return sprintf(implode(';', $dsnParts), ...$dsnConfig);
    }
}
