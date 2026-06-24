<?php

namespace Easi\DB2\Queue;

use Illuminate\Queue\DatabaseQueue;

class DB2Queue extends DatabaseQueue
{

    /**
     * {@inheritdoc}
     *
     * @return bool
     */
    public function getLockForPopping(): bool
    {
        return true;
    }

}
