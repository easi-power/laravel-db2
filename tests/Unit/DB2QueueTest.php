<?php

namespace Tests\Unit;

use Easi\DB2\Database\DB2ConcurrencyErrorDetector;
use Easi\DB2\Database\DB2Connection;
use Easi\DB2\Queue\DB2Connector;
use Easi\DB2\Queue\DB2Queue;
use Illuminate\Container\Container;
use Illuminate\Database\ConnectionResolver;
use PDOException;
use RuntimeException;
use Tests\Unit\Helpers\FakePDO;
use Throwable;

function queueTestPdo(?PDOException $failReservationWith = null): FakePDO
{
    $pdo = new FakePDO();
    $pdo->selectResults = [(object) [
        'id' => 1,
        'queue' => 'default',
        'payload' => json_encode(['uuid' => 'a1', 'displayName' => 'SendInvoice', 'job' => 'SendInvoice', 'data' => []]),
        'attempts' => 0,
        'reserved_at' => null,
        'available_at' => 0,
        'created_at' => 0,
    ]];
    $pdo->exceptionToThrow = $failReservationWith;
    $pdo->throwOnlyFor = 'update';

    return $pdo;
}

function queueTestQueue(FakePDO $pdo): DB2Queue
{
    $queue = new DB2Queue(new DB2Connection($pdo, 'TESTDB', '', ['driver' => 'db2_ibmi_odbc', 'schema' => 'test']), 'jobs', 'default');
    $queue->setContainer(new Container);
    $queue->setConnectionName('db2');

    return $queue;
}

function popAndCatch(DB2Queue $queue): Throwable
{
    try {
        $queue->pop();
    } catch (Throwable $e) {
        return $e;
    }

    throw new RuntimeException('Expected pop() to throw.');
}

function deletesIssued(FakePDO $pdo): int
{
    return count(array_filter($pdo->preparedQueries, fn (string $sql) => str_starts_with($sql, 'delete')));
}

describe('pop', function () {
    // Two workers racing for a job: the reservation hits a lock and must be retried
    // later, not reported as a broken job.
    it('does not fail the job when reserving it hits a DB2 lock conflict', function () {
        $pdo = queueTestPdo(new PDOException('SQL0913 - Row or object JOBS in TEST type *FILE in use.'));

        expect(popAndCatch(queueTestQueue($pdo))->getMessage())->toContain('SQL0913')
            ->and(deletesIssued($pdo))->toBe(0);
    });

    it('still fails the job when reserving it hits any other error', function () {
        $pdo = queueTestPdo(new PDOException('SQL0204 - JOBS in TEST type *FILE not found.'));

        popAndCatch(queueTestQueue($pdo));

        expect(deletesIssued($pdo))->toBe(1);
    });
});

describe('DB2ConcurrencyErrorDetector', function () {
    it('recognises DB2 lock errors', function (PDOException $e) {
        expect((new DB2ConcurrencyErrorDetector)->causedByConcurrencyError($e))->toBeTrue();
    })->with([
        'lock timeout (SQL0913)' => [new PDOException('SQL0913 - Row or object ORDERS in TEST type *FILE in use.')],
        'deadlock (SQL0911)' => [new PDOException('SQL0911 - Deadlock or timeout occurred, reason code 2.')],
        'SQLSTATE 57033' => [new class('lock timeout') extends PDOException {
            protected $code = '57033';
        }],
    ]);

    it('ignores other errors', function () {
        expect((new DB2ConcurrencyErrorDetector)->causedByConcurrencyError(new PDOException('SQL0204 - ORDERS not found.')))
            ->toBeFalse();
    });
});

it('retries a transaction that hits a DB2 deadlock', function () {
    $pdo = new FakePDO();
    $pdo->exceptionToThrow = new PDOException('SQL0911 - Deadlock or timeout occurred, reason code 2.');
    $connection = new DB2Connection($pdo, 'TESTDB', '', ['driver' => 'db2_ibmi_odbc', 'schema' => 'test']);

    expect(fn () => $connection->transaction(fn () => $connection->statement('update orders set status = 1'), 2))
        ->toThrow(PDOException::class)
        ->and($pdo->preparedQueries)->toHaveCount(2);
});

it('passes after_commit through to the queue', function (?bool $afterCommit) {
    $connection = new DB2Connection(new FakePDO(), 'TESTDB', '', ['driver' => 'db2_ibmi_odbc', 'schema' => 'test']);
    $connector = new DB2Connector(new ConnectionResolver(['db2' => $connection]));

    $queue = $connector->connect(array_filter([
        'connection' => 'db2', 'table' => 'jobs', 'queue' => 'default', 'after_commit' => $afterCommit,
    ], fn ($value) => $value !== null));

    expect((fn () => $this->dispatchAfterCommit)->call($queue))->toBe($afterCommit);
})->with([
    'enabled' => [true],
    'disabled' => [false],
    'not configured' => [null],
]);
