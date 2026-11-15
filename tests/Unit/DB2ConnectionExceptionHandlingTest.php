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

function catchQueryException(DB2Connection $connection, string $sql, array $bindings = []): TranslatedQueryException
{
    try {
        $connection->statement($sql, $bindings);
    } catch (TranslatedQueryException $e) {
        return $e;
    }

    throw new Exception('Expected a TranslatedQueryException to be thrown.');
}

function makeFailingDb2Connection(array $config): DB2Connection
{
    $pdo = new FakePDO();
    $pdo->exceptionToThrow = new PDOException('SQL0204 - ORDERS not found.');

    return new DB2Connection($pdo, 'TESTDB', '', $config + ['driver' => 'db2_odbc', 'schema' => 'test']);
}

it('keeps credentials out of the connection details an exception carries', function () {
    $connection = makeFailingDb2Connection([
        'host' => 'db.example.test',
        'username' => 'app',
        'password' => 'hunter2',
        'odbc_keywords' => ['SIGNON' => 3],
    ]);

    $details = catchQueryException($connection, 'select * from orders')->getConnectionDetails();

    expect($details)->toHaveKey('host', 'db.example.test')
        ->and($details)->not->toHaveKeys(['username', 'password', 'odbc_keywords']);
});

it('translates the driver message from the configured encoding', function () {
    $pdo = new FakePDO();
    $pdo->exceptionToThrow = new PDOException("SQL0204 - caf\xE9 not found.");
    $connection = new DB2Connection($pdo, 'TESTDB', '', [
        'driver' => 'db2_odbc', 'schema' => 'test', 'from_encoding' => 'ISO-8859-1',
    ]);

    expect(catchQueryException($connection, 'select * from orders')->getMessage())
        ->toContain('SQL0204 - café not found.');
});

it('substitutes bindings into the message by default', function () {
    $connection = makeFailingDb2Connection([]);

    expect(catchQueryException($connection, 'select * from orders where sku = ?', ['ABC-1'])->getMessage())
        ->toContain("where sku = 'ABC-1'");
});

it('masks bindings in the message when mask_bindings_in_exception_messages is set', function () {
    $connection = makeFailingDb2Connection(['mask_bindings_in_exception_messages' => true]);

    $message = catchQueryException($connection, 'select * from orders where sku = ?', ['ABC-1'])->getMessage();

    expect($message)->toContain('where sku = ?')
        ->and($message)->not->toContain('ABC-1');
});
