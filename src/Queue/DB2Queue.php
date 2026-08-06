<?php

namespace Easi\DB2\Queue;

use Illuminate\Queue\DatabaseQueue;

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

}
