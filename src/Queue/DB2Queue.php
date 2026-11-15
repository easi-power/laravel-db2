<?php

namespace Easi\DB2\Queue;

use Easi\DB2\Database\DB2ConcurrencyErrorDetector;
use Illuminate\Queue\DatabaseQueue;
use Throwable;

class DB2Queue extends DatabaseQueue
{

    /**
     * {@inheritdoc}
     *
     * Returned as an explicit clause rather than `true`: DB2 for i has no
     * FOR UPDATE SKIP LOCKED, and a plain FOR UPDATE would fail on any
     * non-updatable cursor. The isolation-clause form takes exclusive row
     * locks until the popping transaction commits, while SKIP LOCKED DATA lets
     * a second worker walk past rows another worker already reserved instead
     * of blocking on them.
     *
     * @return string
     */
    public function getLockForPopping(): string
    {
        return 'with rs use and keep exclusive locks skip locked data';
    }

    /**
     * Determine if the given exception was caused by a concurrency error.
     *
     * pop() fails the job it was reserving unless the error is a concurrency error, so a
     * DB2 lock conflict between two workers must count as one, or a valid job is failed.
     */
    protected function causedByConcurrencyError(Throwable $e): bool
    {
        return parent::causedByConcurrencyError($e)
            || (new DB2ConcurrencyErrorDetector)->causedByConcurrencyError($e);
    }

}
