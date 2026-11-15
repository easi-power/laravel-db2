<?php

namespace Tests\Unit;

use Easi\DB2\Database\Connectors\ODBCConnector;
use Easi\DB2\Database\Connectors\ODBCZOSConnector;

function dsnFor(ODBCConnector $connector, array $config): string
{
    return (fn () => $this->getDsn($config))->call($connector);
}

const CONNECTOR_TEST_CONFIG = [
    'driverName' => '{IBM i Access ODBC Driver}',
    'host' => 'db.example.test',
    'port' => 446,
    'database' => 'TESTDB',
    'username' => 'app',
    'password' => 'hunter2',
];

// PDO receives the credentials as separate arguments; PDO_ODBC adds them to the
// connection string itself.
it('does not duplicate the credentials in the IBM i DSN', function () {
    $dsn = dsnFor(new ODBCConnector, CONNECTOR_TEST_CONFIG + ['odbc_keywords' => ['NAM' => 0, 'CMT' => 0]]);

    expect($dsn)->toBe('odbc:DRIVER={IBM i Access ODBC Driver};System=db.example.test;Database=TESTDB;NAM=0;CMT=0')
        ->and($dsn)->not->toContain('hunter2')
        ->and($dsn)->not->toContain('app');
});

it('does not duplicate the credentials in the z/OS DSN', function () {
    $dsn = dsnFor(new ODBCZOSConnector, CONNECTOR_TEST_CONFIG);

    expect($dsn)->toBe('odbc:DRIVER={IBM i Access ODBC Driver};Database=TESTDB;Hostname=db.example.test;Port=446;Protocol=TCPIP;')
        ->and($dsn)->not->toContain('hunter2');
});
