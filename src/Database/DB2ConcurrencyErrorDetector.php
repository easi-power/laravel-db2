<?php

namespace Easi\DB2\Database;

use Illuminate\Contracts\Database\ConcurrencyErrorDetector;
use Illuminate\Support\Str;
use PDOException;
use Throwable;

/**
 * Recognises the DB2 lock errors the framework's detector does not know.
 *
 * The framework already catches SQLSTATE 40001. IBM i reports a lock wait timeout as
 * SQL0913 (SQLSTATE 57033), and its ODBC driver can report a deadlock (SQL0911) under
 * a generic SQLSTATE, so both are also matched on the message.
 */
class DB2ConcurrencyErrorDetector implements ConcurrencyErrorDetector
{
    public function causedByConcurrencyError(Throwable $e): bool
    {
        if ($e instanceof PDOException && $e->getCode() === '57033') {
            return true;
        }

        return Str::contains($e->getMessage(), [
            'SQL0911',
            'SQL0913',
            'Row or object',
        ]);
    }
}
