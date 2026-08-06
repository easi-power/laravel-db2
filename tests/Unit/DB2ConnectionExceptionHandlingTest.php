<?php

namespace Tests\Unit;

use Easi\DB2\Database\DB2Connection;
use Easi\DB2\Exceptions\TranslatedQueryException;
use Exception;
use Illuminate\Database\UniqueConstraintViolationException;
use PDOException;
use Throwable;
use Tests\Unit\Helpers\FakePDO;

function makeDb2Connection(Exception $exceptionToThrow): DB2Connection
{
    $pdo = new FakePDO();
    $pdo->exceptionToThrow = $exceptionToThrow;

    return new DB2Connection($pdo, 'TESTDB', '', ['driver' => 'db2_odbc', 'schema' => 'test']);
}

it('throws a UniqueConstraintViolationException for a SQL0803 duplicate key error, wrapped only once', function () {
    $pdoException = new PDOException(
        'SQLSTATE[23000]: Integrity constraint violation: -803 [IBM][System i Access ODBC Driver][DB2 for i5/OS]SQL0803 - Duplicate key value specified.'
    );
    $pdoException->errorInfo = ['23000', -803, 'SQL0803 - Duplicate key value specified.'];

    $connection = makeDb2Connection($pdoException);

    $sql = 'update SAMPLE_TABLE set status_flag = ? where id = ?';

    try {
        $connection->statement($sql, ['A', 1]);
        expect(false)->toBeTrue('Expected an exception to be thrown.');
    } catch (Throwable $e) {
        expect($e)->toBeInstanceOf(UniqueConstraintViolationException::class)
            ->and($e)->not->toBeInstanceOf(TranslatedQueryException::class)
            ->and(substr_count($e->getMessage(), '(Connection:'))->toBe(1)
            ->and($e->getMessage())->toContain('SQL0803 - Duplicate key value specified');

        // Regression guard: the old handleQueryException override double-wrapped
        // the message, so "(Connection: ..." appeared twice in production logs.
    }
});

it('throws a TranslatedQueryException for a generic query error, wrapped only once', function () {
    $pdoException = new PDOException('SQLSTATE[42S02]: Base table or view not found: -204 SQL0204 - MISSING_TABLE not found.');
    $pdoException->errorInfo = ['42S02', -204, 'SQL0204 - MISSING_TABLE not found.'];

    $connection = makeDb2Connection($pdoException);

    $sql = 'select * from MISSING_TABLE';

    try {
        $connection->statement($sql);
        expect(false)->toBeTrue('Expected an exception to be thrown.');
    } catch (Throwable $e) {
        expect($e)->toBeInstanceOf(TranslatedQueryException::class)
            ->and($e)->not->toBeInstanceOf(UniqueConstraintViolationException::class)
            ->and(substr_count($e->getMessage(), '(Connection:'))->toBe(1)
            ->and($e->getMessage())->toContain('SQL0204 - MISSING_TABLE not found')
            ->and($e->getMessage())->toContain('select * from MISSING_TABLE');

    }
});
