<?php

namespace Easi\DB2\Exceptions;

use Illuminate\Database\QueryException;

class TranslatedQueryException extends QueryException
{
    use TranslatesQueryMessage;
}
