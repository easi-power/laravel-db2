<?php

namespace Easi\DB2\Database\Gateway;

use PDOException;

/**
 * An error frame from the gateway. Extends PDOException so QueryException keeps the
 * SQLSTATE as a string code (a plain Exception would int-cast '23505' to 23505).
 */
class GatewayException extends PDOException
{
    public function __construct(string $sqlState, string $message)
    {
        parent::__construct($message);

        $this->code = $sqlState;
        $this->errorInfo = [$sqlState, null, $message];
    }
}
