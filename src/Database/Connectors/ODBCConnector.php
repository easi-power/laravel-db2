<?php

namespace Easi\DB2\Database\Connectors;

/**
 * Class ODBCConnector
 *
 * @package Easi\DB2\Database\Connectors
 */
class ODBCConnector extends DB2Connector
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
            'SYSTEM=%s',
            'DATABASE=%s',
            'USERID=%s',
            'PASSWORD=%s',
        ];

        $dsnConfig = [
            $config['driverName'],
            $config['host'],
            $config['database'],
            $config['username'],
            $config['password'],
        ];

        if (array_key_exists('odbc_keywords', $config)) {
            $odbcKeywords = $config['odbc_keywords'];
            $parts = array_map(function($part) {
                return $part . '=%s';
            }, array_keys($odbcKeywords));
            $config = array_values($odbcKeywords);

            $dsnParts = array_merge($dsnParts, $parts);
            $dsnConfig = array_merge($dsnConfig, $config);
        }

        return sprintf(implode(';', $dsnParts), ...$dsnConfig);
    }
}
